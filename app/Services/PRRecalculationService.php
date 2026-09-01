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
            
            // Process each log chronologically
            foreach ($logs as $log) {
                // Detect PRs by comparing against logs before this one
                // We pass the log's ID so it doesn't compare against itself
                $prs = $this->prDetectionService->detectPRsWithDetails($log);
                
                // Create PR records
                foreach ($prs as $pr) {
                    PersonalRecord::create([
                        'user_id' => $log->user_id,
                        'exercise_id' => $log->exercise_id,
                        'lift_log_id' => $log->id,
                        'pr_type' => $pr['type'],
                        'rep_count' => $pr['rep_count'] ?? null,
                        'weight' => $pr['weight'] ?? null,
                        'unit' => $pr['unit'] ?? ($log->liftSets->first()->unit ?? 'lbs'),
                        'value' => $pr['value'],
                        'previous_pr_id' => $pr['previous_pr_id'] ?? null,
                        'previous_value' => $pr['previous_value'] ?? null,
                        'achieved_at' => $log->logged_at,
                    ]);
                }
                
                // Update lift log flags
                $log->update([
                    'is_pr' => count($prs) > 0,
                    'pr_count' => count($prs),
                ]);
            }

            // Re-link previous_pr_id chains across the freshly-created rows.
            //
            // detectPRsWithDetails() resolves previous_pr_id by querying existing PersonalRecord
            // rows — but during a full rebuild every prior row was just deleted at the top of this
            // transaction, so that lookup finds nothing and every rebuilt row lands with a NULL
            // previous_pr_id. With no chain, the `current()` scope (whereDoesntHave('supersededBy'))
            // treats EVERY row as current, so the "Not beaten" table lists every historical value
            // (e.g. every 5-rep max: 175, 185, 195 … 250) instead of only the latest.
            //
            // Fix: after all rows exist, link each keyed group chronologically so only the tail of
            // each chain is current. The keying mirrors the PR descriptors: rep-max keys by
            // rep_count (weight is the ascending value), density/hypertrophy key by weight, and
            // scalar types (one_rm, volume, load, distance, duration, speed, time) form one chain
            // per type.
            $this->relinkPreviousPrChains($userId, $exerciseId);
        });
    }

    /**
     * Rebuild previous_pr_id linkage for all (non-deleted) PRs of one exercise so each keyed group
     * forms a single chronological chain (only the latest row in each group stays "current").
     */
    private function relinkPreviousPrChains(int $userId, int $exerciseId): void
    {
        $log = LiftLog::where('user_id', $userId)
            ->where('exercise_id', $exerciseId)
            ->with('exercise')
            ->first();

        if (!$log || !$log->exercise) {
            return;
        }

        $family = $this->prEngine->resolveFamily(
            $log->exercise->log_type ?? null,
            $log->exercise->exercise_type ?? null
        );
        $descriptors = $family ? config("pr_families.families.{$family}", []) : [];
        $descriptorMap = array_column($descriptors, null, 'type');

        $rows = PersonalRecord::where('user_id', $userId)
            ->where('exercise_id', $exerciseId)
            ->orderBy('achieved_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // Group rows into chains by their keying identity.
        $chains = [];
        foreach ($rows as $row) {
            $chains[$this->chainKey($row, $descriptorMap[$row->pr_type] ?? null)][] = $row;
        }

        // Within each chain, point every row at its immediate predecessor; head points at null.
        foreach ($chains as $chain) {
            $previousId = null;
            $previousValue = null;
            foreach ($chain as $row) {
                $newPrev = $previousId;
                if ($row->previous_pr_id !== $newPrev) {
                    $row->previous_pr_id = $newPrev;
                    // Keep previous_value consistent with the linked predecessor (null for the head).
                    $row->previous_value = $previousValue;
                    $row->save();
                }
                $previousId = $row->id;
                $previousValue = $row->value;
            }
        }
    }

    /**
     * Compute the chain-grouping key for a PR row from its descriptor. rep-max chains group by
     * rep_count; weight-keyed chains (density/hypertrophy) group by weight; everything else is a
     * single chain per pr_type.
     */
    private function chainKey(PersonalRecord $row, ?array $descriptor): string
    {
        $store = $descriptor['store'] ?? '';
        $keyField = $descriptor['keyFields'][0] ?? null;

        if ($store === 'keyedByReps' || $keyField === 'reps' || $keyField === 'rounds') {
            return $row->pr_type . '|reps=' . (string) $row->rep_count;
        }

        if ($store === 'keyedByKey' || $keyField === 'weight') {
            return $row->pr_type . '|weight=' . (string) $row->weight;
        }

        // consistency keys by set count (stored in rep_count); scalars have no key.
        if ($row->pr_type === 'consistency') {
            return $row->pr_type . '|reps=' . (string) $row->rep_count;
        }

        return $row->pr_type;
    }
}
