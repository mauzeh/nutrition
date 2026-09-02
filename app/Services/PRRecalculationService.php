<?php

namespace App\Services;

use App\Models\LiftLog;
use App\Models\PersonalRecord;
use App\Services\PR\PrEngine;
use Illuminate\Support\Facades\DB;

class PRRecalculationService
{
    public function __construct(
        protected PRDetectionService $prDetectionService,
        protected PrEngine $prEngine
    ) {}
    
    /**
     * Recalculate ALL PRs for an exercise
     * This is called when a lift is updated, backdated, or deleted
     * 
     * @param int $userId User ID
     * @param int $exerciseId Exercise ID
     * @return void
     */
    public function recalculateAllPRsForExercise(int $userId, int $exerciseId): void
    {
        DB::transaction(function () use ($userId, $exerciseId) {
            // Delete ALL existing PR records for this exercise
            PersonalRecord::where('user_id', $userId)
                ->where('exercise_id', $exerciseId)
                ->delete();

            // Get ALL lift logs for this exercise, ordered chronologically
            $logs = LiftLog::where('user_id', $userId)
                ->where('exercise_id', $exerciseId)
                ->with(['exercise', 'liftSets'])
                ->orderBy('logged_at', 'asc')
                ->orderBy('id', 'asc') // Secondary sort for same timestamp
                ->get();

            if ($logs->isEmpty()) {
                return; // No logs → nothing to rebuild (the delete above cleared any orphans).
            }

            // SINGLE-PASS rebuild. The former loop called detectPRsWithDetails() per log, which
            // re-queried and re-computed ALL previous logs every time (O(n²) queries + compute) plus a
            // per-PR previous_pr_id lookup, then a SECOND relink pass that re-loaded every row and
            // saved each one individually (an N+1 writer). Instead: resolve the family once, walk the
            // logs chronologically accumulating history IN MEMORY, shape PRs from metrics we already
            // have, BATCH-insert, then link previous_pr_id chains in memory + ONE CASE update. Output
            // is identical (same history fold, same chronological chains); only the cost changes. The
            // per-save detectPRsWithDetails path is untouched.
            $exercise = $logs->first()->exercise;
            $family = $exercise
                ? $this->prEngine->resolveFamily($exercise->log_type, $exercise->exercise_type)
                : null;

            if (!$family) {
                // Family unresolvable / no PRs (e.g. banded) — nothing to build; delete already ran.
                LiftLog::whereIn('id', $logs->pluck('id'))->update(['is_pr' => false, 'pr_count' => 0]);
                return;
            }

            // Build all PR rows for this exercise's logs in memory (chronological pass, no DB).
            [$rowsToInsert, $chainKeys, $prCounts] = $this->buildRowsForLogs($logs, $family);

            // Flag updates in TWO queries regardless of log count: reset all, then one CASE update
            // for the logs that scored ≥1 PR.
            LiftLog::whereIn('id', $logs->pluck('id'))->update(['is_pr' => false, 'pr_count' => 0]);
            $this->applyPrCountFlags(array_filter($prCounts, fn ($c) => $c > 0));

            if (empty($rowsToInsert)) {
                return;
            }

            // Insert, then read back ids in the SAME (achieved_at, id) order the rows were built so
            // we can map inserted ids positionally and link previous_pr_id chains in memory.
            PersonalRecord::insert($rowsToInsert);

            $insertedIds = PersonalRecord::where('user_id', $userId)
                ->where('exercise_id', $exerciseId)
                ->orderBy('achieved_at', 'asc')
                ->orderBy('id', 'asc')
                ->pluck('id')
                ->all();

            $this->applyChainLinks($this->linkChains($rowsToInsert, $chainKeys, $insertedIds));
        });
    }

    /**
     * Set-wide, TRANSACTION-FREE batch recalc for the historical/migration path (prs:calculate-historical
     * and data migrations). PRs are a derived cache, not a blocking feature — a mid-run crash is safe to
     * simply re-run — so we skip the per-pair transaction and instead do the whole matrix in a handful of
     * set-wide queries: ONE load of every target log+set, group + compute in memory, ONE bulk delete,
     * CHUNKED bulk insert (to stay under max_allowed_packet), then set-wide flag + chain-link updates.
     * The per-pair recalculateAllPRsForExercise stays transactional for the LIVE paths (lift save/edit/
     * delete) that run in a user request and must be atomic.
     *
     * @param int[]|null $userIds     Restrict to these users (null = all).
     * @param int[]|null $exerciseIds Restrict to these exercises (null = all).
     * @param callable|null $onProgress Optional (int $doneCombos, int $totalCombos) tick for CLI progress.
     */
    public function recalculateAllPRsBatch(?array $userIds = null, ?array $exerciseIds = null, ?callable $onProgress = null): void
    {
        // NO ELOQUENT on the hot path. Hydrating LiftLog/LiftSet models (attribute casting, mutators,
        // relations, magic __get) dominated the CPU (~1.5s over ~1700 logs). Instead we read plain rows
        // with the query builder, compute over native arrays, and write with the query builder. The
        // engine (computeMetrics/detectPRs/Reductions) already reads set fields via array access
        // (extractValue's is_array branch), so it consumes native rows unchanged.

        // STREAM BY USER-BATCH so peak memory stays flat regardless of user count (at 800 users ×
        // ~100 logs the load-everything-at-once approach would hold ~300MB+ of native rows and OOM a
        // hobby box). PR chains never cross users, so a user is a clean processing boundary: we load
        // only one batch of users' logs+sets at a time, compute, flush, release. The set-wide null +
        // delete run ONCE up front (single bulk statements, no memory cost); ids are pre-assigned from
        // a running counter so the insert stays dumb (previous_pr_id baked in, no read-back/CASE).

        // Resolve the target user id list once (small — one int per user). Union of users that have
        // logs and users that have PR rows (the latter catches orphan pairs to clear).
        $userQuery = DB::table('lift_logs')->whereNull('deleted_at')->distinct();
        if ($userIds !== null) {
            $userQuery->whereIn('user_id', $userIds);
        }
        if ($exerciseIds !== null) {
            $userQuery->whereIn('exercise_id', $exerciseIds);
        }
        $targetUserIds = $userQuery->pluck('user_id');

        $prUserQuery = DB::table('personal_records')->distinct();
        if ($userIds !== null) {
            $prUserQuery->whereIn('user_id', $userIds);
        }
        if ($exerciseIds !== null) {
            $prUserQuery->whereIn('exercise_id', $exerciseIds);
        }
        $targetUserIds = $targetUserIds->merge($prUserQuery->pluck('user_id'))->unique()->values()->all();

        if (empty($targetUserIds)) {
            return;
        }

        // Set-wide null-self-FK + delete, ONCE. (Self-FK: nulling references before delete avoids the
        // constraint violation; includes soft-deleted rows since the delete is physical.)
        $this->clearTargetPRs($userIds, $exerciseIds);

        // Running id counter for the dumb insert: ids from MAX(id)+1 upward are free (we just deleted
        // the target rows; other users' rows are untouched but keep their existing ids, so MAX+1 is
        // safe across the whole run). Advances per inserted row, across batches.
        $nextId = ((int) DB::table('personal_records')->max('id')) + 1;
        $now = now();
        $done = 0;
        $total = count($targetUserIds);

        // Process ~USER_BATCH users at a time. Memory is bounded by one batch's logs+sets.
        foreach (array_chunk($targetUserIds, self::USER_BATCH) as $userBatch) {
            $nextId = $this->recalcUserBatch($userBatch, $exerciseIds, $nextId, $now);
            $done += count($userBatch);
            if ($onProgress) {
                $onProgress(min($done, $total), $total);
            }
        }
    }

    /**
     * Rebuild PRs for one batch of users. Loads only these users' logs+sets, computes, flushes writes,
     * and returns the advanced id counter. Peak memory is bounded by this batch. Assumes the target PR
     * rows were already deleted by the caller.
     *
     * @param int[]    $userBatch
     * @param int[]|null $exerciseIds
     * @return int the next free id after this batch's inserts
     */
    private function recalcUserBatch(array $userBatch, ?array $exerciseIds, int $nextId, \Illuminate\Support\Carbon $now): int
    {
        // Logs for these users, joined to exercises for family resolution, ordered so a linear scan
        // yields chronological logs per (user, exercise).
        $logQuery = DB::table('lift_logs')
            ->join('exercises', 'exercises.id', '=', 'lift_logs.exercise_id')
            ->whereNull('lift_logs.deleted_at')
            ->whereIn('lift_logs.user_id', $userBatch)
            ->orderBy('lift_logs.user_id')
            ->orderBy('lift_logs.exercise_id')
            ->orderBy('lift_logs.logged_at')
            ->orderBy('lift_logs.id')
            ->select([
                'lift_logs.id', 'lift_logs.user_id', 'lift_logs.exercise_id', 'lift_logs.logged_at',
                'exercises.log_type', 'exercises.exercise_type',
            ]);
        if ($exerciseIds !== null) {
            $logQuery->whereIn('lift_logs.exercise_id', $exerciseIds);
        }
        $logRows = $logQuery->get()->all();

        if (empty($logRows)) {
            return $nextId;
        }

        // This batch's sets, grouped by lift_log_id.
        $logIds = array_map(fn ($l) => $l->id, $logRows);
        $setsByLog = [];
        foreach (DB::table('lift_sets')
            ->whereNull('deleted_at')
            ->whereIn('lift_log_id', $logIds)
            ->orderBy('id')
            ->get(['lift_log_id', 'weight', 'unit', 'reps', 'time', 'distance', 'distance_unit', 'calories', 'band_color']) as $s) {
            $setsByLog[$s->lift_log_id][] = (array) $s;
        }

        $rowsToInsert = [];
        $prCounts = [];       // logId => count (only >0)
        $lastIdInChain = [];  // chainKey => assigned id of the previous row (per user, reset below)

        $i = 0;
        $n = count($logRows);
        while ($i < $n) {
            $first = $logRows[$i];
            $userId = $first->user_id;
            $exerciseId = $first->exercise_id;
            $family = $this->prEngine->resolveFamily($first->log_type, $first->exercise_type);

            $group = [];
            while ($i < $n && $logRows[$i]->user_id === $userId && $logRows[$i]->exercise_id === $exerciseId) {
                $group[] = $logRows[$i];
                $i++;
            }

            if (!$family) {
                continue;
            }

            [$rows, $chainKeys, $counts] = $this->buildRowsForNativeLogs($group, $setsByLog, $family, $now);

            // Pre-assign ids + bake previous_pr_id from the running counter (chains are per-pair, so a
            // fresh chain map per group is correct; the counter is global so ids never collide).
            $chainState = [];
            foreach ($rows as $j => $row) {
                $id = $nextId++;
                $row['id'] = $id;
                $key = $chainKeys[$j];
                $row['previous_pr_id'] = $chainState[$key] ?? null;
                $chainState[$key] = $id;
                $rowsToInsert[] = $row;
            }
            foreach ($counts as $logId => $c) {
                if ($c > 0) {
                    $prCounts[$logId] = $c;
                }
            }
        }

        // Flush this batch: reset flags for all its logs, set counts for scorers, dumb-insert the rows.
        foreach (array_chunk($logIds, self::CHUNK) as $chunk) {
            DB::table('lift_logs')->whereIn('id', $chunk)->update(['is_pr' => false, 'pr_count' => 0]);
        }
        foreach (array_chunk($prCounts, self::CHUNK, true) as $chunk) {
            $this->applyPrCountFlags($chunk);
        }
        foreach (array_chunk($rowsToInsert, self::CHUNK) as $chunk) {
            DB::table('personal_records')->insert($chunk);
        }

        return $nextId;
    }

    /**
     * Null the self-referential previous_pr_id links for the target scope, then physically delete the
     * target PR rows. Two set-wide statements, no memory cost. Nulling first avoids the self-FK
     * violation a bulk parent delete would otherwise trigger.
     */
    private function clearTargetPRs(?array $userIds, ?array $exerciseIds): void
    {
        $nullQuery = DB::table('personal_records')->whereNotNull('previous_pr_id');
        if ($userIds !== null) {
            $nullQuery->whereIn('user_id', $userIds);
        }
        if ($exerciseIds !== null) {
            $nullQuery->whereIn('exercise_id', $exerciseIds);
        }
        $nullQuery->update(['previous_pr_id' => null]);

        $deleteQuery = DB::table('personal_records');
        if ($userIds !== null) {
            $deleteQuery->whereIn('user_id', $userIds);
        }
        if ($exerciseIds !== null) {
            $deleteQuery->whereIn('exercise_id', $exerciseIds);
        }
        $deleteQuery->delete();
    }

    /**
     * Native-row variant of buildRowsForLogs: consumes plain log rows (stdClass with id/user_id/
     * exercise_id/logged_at) + a lift_log_id => set-arrays map, with ZERO Eloquent. Feeds the engine
     * native arrays (['lift_sets' => [...]]) — computeMetrics/extractValue read array access directly.
     * Byte-identical output to buildRowsForLogs; the only difference is the input type (native vs model).
     *
     * @return array{0: array<int,array<string,mixed>>, 1: array<int,string>, 2: array<int,int>}
     */
    private function buildRowsForNativeLogs(array $logRows, array $setsByLog, string $family, \Illuminate\Support\Carbon $now): array
    {
        $descriptorMap = array_column(config("pr_families.families.{$family}", []), null, 'type');

        $history = [];
        $rows = [];
        $chainKeys = [];
        $prCounts = [];

        foreach ($logRows as $log) {
            $sets = $setsByLog[$log->id] ?? [];
            $metrics = $this->prEngine->computeMetrics(['lift_sets' => $sets], $family);
            $detected = $this->prEngine->detectPRs($metrics, $history, $family);

            $logUnit = $sets[0]['unit'] ?? 'lbs';
            $prs = $this->prDetectionService->shapeDetailedPRs($detected['prs'], $logUnit, count($sets));

            foreach ($prs as $pr) {
                $rows[] = [
                    'user_id' => $log->user_id,
                    'exercise_id' => $log->exercise_id,
                    'lift_log_id' => $log->id,
                    'pr_type' => $pr['type'],
                    'rep_count' => $pr['rep_count'] ?? null,
                    'weight' => $pr['weight'] ?? null,
                    'unit' => $pr['unit'] ?? $logUnit,
                    'value' => $pr['value'],
                    'previous_pr_id' => null,
                    'previous_value' => $pr['previous_value'] ?? null,
                    'achieved_at' => $log->logged_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $chainKeys[] = $this->chainKeyForPr($pr, $descriptorMap[$pr['type']] ?? null);
            }

            $prCounts[$log->id] = count($prs);
            $this->prDetectionService->foldMetricsIntoHistory($history, $metrics, $family);
        }

        return [$rows, $chainKeys, $prCounts];
    }

    /**
     * Chunk size for bulk insert / CASE updates — small enough to stay under MySQL's default
     * max_allowed_packet and cap memory + lock duration, large enough to keep the query count tiny.
     */
    private const CHUNK = 500;

    /**
     * Users processed per streamed batch in recalculateAllPRsBatch. Bounds peak memory to one batch's
     * logs+sets (≈ USER_BATCH × ~100 logs × ~4 sets) rather than the whole dataset, so the batch recalc
     * stays flat-memory from 12 users to 800+.
     */
    private const USER_BATCH = 50;

    /**
     * Build the PR rows for ONE exercise's chronologically-ordered logs, in memory, with zero DB
     * access. Returns [rows, chainKeys, prCounts] where rows[] are insert-ready arrays (previous_pr_id
     * null — linked after insert), chainKeys[] is parallel to rows[] (the supersession chain each row
     * belongs to), and prCounts is logId => PR count. Shared by the per-pair and batch recalc paths so
     * both produce byte-identical rows.
     *
     * @return array{0: array<int,array<string,mixed>>, 1: array<int,string>, 2: array<int,int>}
     */
    private function buildRowsForLogs(\Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection $logs, string $family, ?\Illuminate\Support\Carbon $now = null): array
    {
        $now ??= now();
        $descriptorMap = array_column(config("pr_families.families.{$family}", []), null, 'type');

        $history = [];
        $rows = [];
        $chainKeys = [];
        $prCounts = [];

        foreach ($logs as $log) {
            $metrics = $this->prEngine->computeMetrics($log, $family);
            $detected = $this->prEngine->detectPRs($metrics, $history, $family);

            $logUnit = $log->liftSets->first()->unit ?? 'lbs';
            $prs = $this->prDetectionService->shapeDetailedPRs(
                $detected['prs'],
                $logUnit,
                $log->liftSets->count()
            );

            foreach ($prs as $pr) {
                $rows[] = [
                    'user_id' => $log->user_id,
                    'exercise_id' => $log->exercise_id,
                    'lift_log_id' => $log->id,
                    'pr_type' => $pr['type'],
                    'rep_count' => $pr['rep_count'] ?? null,
                    'weight' => $pr['weight'] ?? null,
                    'unit' => $pr['unit'] ?? $logUnit,
                    'value' => $pr['value'],
                    'previous_pr_id' => null, // linked after insert
                    'previous_value' => $pr['previous_value'] ?? null,
                    'achieved_at' => $log->logged_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $chainKeys[] = $this->chainKeyForPr($pr, $descriptorMap[$pr['type']] ?? null);
            }

            $prCounts[$log->id] = count($prs);

            // Fold this log into the running history for the next log's comparison.
            $this->prDetectionService->foldMetricsIntoHistory($history, $metrics, $family);
        }

        return [$rows, $chainKeys, $prCounts];
    }

    /**
     * Map inserted ids back to their in-memory rows positionally and compute each row's chain link.
     * $insertedIds[i] corresponds to $rows[i] (both in the same build/read-back order). Each row points
     * at the previous inserted row sharing its chain key; the head of each chain links to null.
     *
     * @return array<int, array{previous_pr_id: ?int, previous_value: ?float}> prId => link
     */
    private function linkChains(array $rows, array $chainKeys, array $insertedIds): array
    {
        $lastInChain = []; // chainKey => ['id' => int, 'value' => float]
        $links = [];
        foreach ($rows as $i => $row) {
            $prId = $insertedIds[$i];
            $key = $chainKeys[$i];
            $prev = $lastInChain[$key] ?? null;
            $links[$prId] = [
                'previous_pr_id' => $prev['id'] ?? null,
                'previous_value' => $prev['value'] ?? null,
            ];
            $lastInChain[$key] = ['id' => $prId, 'value' => $row['value']];
        }

        return $links;
    }

    /**
     * Set is_pr=true + pr_count for the given logs (logId => count, all counts > 0) in ONE UPDATE
     * using a CASE expression for pr_count. Logs not in this map were already reset to 0/false.
     *
     * @param array<int,int> $countsByLogId
     */
    private function applyPrCountFlags(array $countsByLogId): void
    {
        if (empty($countsByLogId)) {
            return;
        }

        $ids = array_keys($countsByLogId);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $case = 'CASE id';
        $caseBindings = [];
        foreach ($countsByLogId as $id => $count) {
            $case .= ' WHEN ? THEN ?';
            $caseBindings[] = $id;
            $caseBindings[] = $count;
        }
        $case .= ' END';

        $table = (new LiftLog())->getTable();
        $sql = "UPDATE {$table} SET is_pr = 1, pr_count = {$case}, updated_at = ? WHERE id IN ({$placeholders})";
        $bindings = array_merge($caseBindings, [now()], $ids);

        DB::update($sql, $bindings);
    }

    /**
     * Set previous_pr_id + previous_value for many PR rows in a single UPDATE using CASE expressions,
     * replacing the former per-row ->save() loop (the recalc's remaining N+1 writer).
     *
     * @param array<int, array{previous_pr_id: ?int, previous_value: ?float}> $links prId => link
     */
    private function applyChainLinks(array $links): void
    {
        // Only rows whose link is non-null need updating (rows were inserted with null/null, which is
        // already correct for a chain head).
        $changed = array_filter($links, fn ($l) => $l['previous_pr_id'] !== null);
        if (empty($changed)) {
            return;
        }

        // Build a single UPDATE ... SET previous_pr_id = CASE id WHEN ? THEN ? ... END, likewise for
        // previous_value, over exactly the changed ids. All values are bound (no interpolation).
        $ids = array_keys($changed);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $prevIdCase = 'CASE id';
        $prevValCase = 'CASE id';
        $prevIdBindings = [];
        $prevValBindings = [];
        foreach ($changed as $id => $link) {
            $prevIdCase .= ' WHEN ? THEN ?';
            $prevIdBindings[] = $id;
            $prevIdBindings[] = $link['previous_pr_id'];

            $prevValCase .= ' WHEN ? THEN ?';
            $prevValBindings[] = $id;
            $prevValBindings[] = $link['previous_value'];
        }
        $prevIdCase .= ' END';
        $prevValCase .= ' END';

        $table = (new PersonalRecord())->getTable();
        $sql = "UPDATE {$table} SET previous_pr_id = {$prevIdCase}, previous_value = {$prevValCase} WHERE id IN ({$placeholders})";
        $bindings = array_merge($prevIdBindings, $prevValBindings, $ids);

        DB::update($sql, $bindings);
    }

    /**
     * Chain-grouping key computed directly from a shaped PR item + its descriptor (no PersonalRecord
     * model needed). Mirror of the former chainKey(PersonalRecord) — rep-max/rounds chains key by
     * rep_count, weight-keyed chains by weight, consistency by set count, scalars one chain per type.
     */
    private function chainKeyForPr(array $pr, ?array $descriptor): string
    {
        $type = $pr['type'];
        $store = $descriptor['store'] ?? '';
        $keyField = $descriptor['keyFields'][0] ?? null;

        if ($store === 'keyedByReps' || $keyField === 'reps' || $keyField === 'rounds') {
            return $type . '|reps=' . (string) ($pr['rep_count'] ?? '');
        }

        if ($store === 'keyedByKey' || $keyField === 'weight') {
            return $type . '|weight=' . (string) ($pr['weight'] ?? '');
        }

        if ($type === 'consistency') {
            return $type . '|reps=' . (string) ($pr['rep_count'] ?? '');
        }

        return $type;
    }
}
