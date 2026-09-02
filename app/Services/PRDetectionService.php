<?php

namespace App\Services;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\PR\PrEngine;
use Illuminate\Database\Eloquent\Collection;

class PRDetectionService
{
    private ?array $lastCalculationSnapshot = null;

    public function __construct(
        private PrEngine $prEngine
    ) {}

    /**
     * Check if a single lift log is a PR compared to previous lifts.
     * Returns string[] array of detected pr_types.
     * Evaluates to truthy array if PRs exist, empty array if not.
     *
     * @param LiftLog $liftLog
     * @param Exercise $exercise
     * @param User $user
     * @return array Array of detected pr_type strings
     */
    public function isLiftLogPR(LiftLog $liftLog, Exercise $exercise, User $user): array
    {
        $family = $this->prEngine->resolveFamily($exercise->log_type, $exercise->exercise_type);

        if (!$family) {
            $this->lastCalculationSnapshot = [
                'supported_pr_types' => [],
                'reason' => 'Exercise log_type does not support PR tracking',
            ];
            return [];
        }

        $previousLogs = LiftLog::where('exercise_id', $exercise->id)
            ->where('user_id', $user->id)
            ->where(function ($q) use ($liftLog) {
                $q->where('logged_at', '<', $liftLog->logged_at)
                  ->orWhere(function ($q2) use ($liftLog) {
                      $q2->where('logged_at', '=', $liftLog->logged_at)
                         ->where('id', '<', $liftLog->id);
                  });
            })
            ->with('liftSets')
            ->orderBy('logged_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $history = $this->buildHistoryFromPreviousLogs($previousLogs, $exercise, $family);
        $currentMetrics = $this->prEngine->computeMetrics($liftLog, $family);
        $result = $this->prEngine->detectPRs($currentMetrics, $history, $family);

        $detectedTypes = array_unique(array_column($result['prs'], 'type'));

        $whyNotPr = [];
        foreach ($result['reasons'] as $r) {
            $keyName = $r['type'];
            if (isset($r['key'])) {
                $keyName .= '_' . $r['key'];
            }
            $whyNotPr[$keyName] = $r;
        }

        $this->lastCalculationSnapshot = [
            'family' => $family,
            'current_metrics' => $currentMetrics,
            'detected_prs' => $result['prs'],
            'reasons' => $result['reasons'],
            'current_lift' => $currentMetrics,
            'previous_logs_count' => $previousLogs->count(),
            'previous_bests' => $history,
            'why_not_pr' => $whyNotPr,
            'pr_reasons' => $result['prs'],
        ];

        return $detectedTypes;
    }

    public function getLastCalculationSnapshot(): ?array
    {
        return $this->lastCalculationSnapshot;
    }

    public function calculatePRLogIds(Collection $liftLogs): array
    {
        if ($liftLogs->isEmpty()) {
            return [];
        }

        $prLogIds = [];
        $logsByExercise = $liftLogs->groupBy('exercise_id');

        foreach ($logsByExercise as $exerciseId => $exerciseLogs) {
            $firstLog = $exerciseLogs->first();
            $exercise = $firstLog->exercise;
            $family = $this->prEngine->resolveFamily($exercise->log_type, $exercise->exercise_type);

            if (!$family) {
                continue;
            }

            $sortedLogs = $exerciseLogs->sortBy('logged_at');

            foreach ($sortedLogs as $index => $log) {
                $previousLogs = $sortedLogs->take($index);
                $history = $this->buildHistoryFromPreviousLogs($previousLogs, $exercise, $family);
                $currentMetrics = $this->prEngine->computeMetrics($log, $family);
                $result = $this->prEngine->detectPRs($currentMetrics, $history, $family);

                if (!empty($result['prs'])) {
                    $prLogIds[] = $log->id;
                }
            }
        }

        return array_unique($prLogIds);
    }

    public function detectPRsWithDetails(LiftLog $liftLog): array
    {
        // Resolve the exercise even if it's soft-deleted — the log still belongs to it and its
        // log_type/exercise_type drive family resolution. Without withTrashed(), a soft-deleted
        // exercise (or user) makes the relation null and reading ->id/->log_type throws.
        $exercise = $liftLog->exercise ?? $liftLog->exercise()->withTrashed()->first();

        // A log with no resolvable exercise can't be scored — return no PRs (recalc then clears any
        // stale rows for the pair). Use the log's own foreign keys for scoping, which are always
        // present regardless of whether the parent user/exercise is soft-deleted.
        if (!$exercise) {
            return [];
        }

        $userId = $liftLog->user_id;
        $family = $this->prEngine->resolveFamily($exercise->log_type, $exercise->exercise_type);

        if (!$family) {
            return [];
        }

        $previousLogs = LiftLog::where('exercise_id', $exercise->id)
            ->where('user_id', $userId)
            ->where(function ($q) use ($liftLog) {
                $q->where('logged_at', '<', $liftLog->logged_at)
                  ->orWhere(function ($q2) use ($liftLog) {
                      $q2->where('logged_at', '=', $liftLog->logged_at)
                         ->where('id', '<', $liftLog->id);
                  });
            })
            ->with('liftSets')
            ->orderBy('logged_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $history = $this->buildHistoryFromPreviousLogs($previousLogs, $exercise, $family);
        $currentMetrics = $this->prEngine->computeMetrics($liftLog, $family);
        $result = $this->prEngine->detectPRs($currentMetrics, $history, $family);

        $logUnit = $liftLog->liftSets->first()->unit ?? 'lbs';

        // Shape the engine output into persistence-ready items (kg de-normalization + column mapping).
        $detailedPrs = $this->shapeDetailedPRs($result['prs'], $logUnit, $liftLog->liftSets->count());

        // Enrich previous_pr_id for the single-log-at-save path (recalc does NOT use this — it rebuilds
        // chains via relinkPreviousPrChains — so this per-PR lookup lives only here, not in shapeDetailedPRs).
        foreach ($result['prs'] as $i => $pr) {
            if ($pr['previous_value'] === null) {
                continue;
            }
            $prItem = $detailedPrs[$i];
            $prevPrQuery = PersonalRecord::where('user_id', $userId)
                ->where('exercise_id', $exercise->id)
                ->where('pr_type', $pr['type']);

            if (isset($prItem['rep_count'])) {
                $prevPrQuery->where('rep_count', $prItem['rep_count']);
            }
            if (isset($prItem['weight'])) {
                $prevPrQuery->where('weight', $prItem['weight']);
            }

            $prevRecord = $prevPrQuery->latest('id')->first();
            if ($prevRecord) {
                $detailedPrs[$i]['previous_pr_id'] = $prevRecord->id;
            }
        }

        return $detailedPrs;
    }

    private function buildHistoryFromPreviousLogs(Collection $previousLogs, Exercise $exercise, string $family): array
    {
        $history = [];

        foreach ($previousLogs as $prevLog) {
            $prevMetrics = $this->prEngine->computeMetrics($prevLog, $family);
            $this->foldMetricsIntoHistory($history, $prevMetrics, $family);
        }

        return $history;
    }

    /**
     * Fold one log's computed metrics into a running history map (best-by-direction for scalar
     * types, best-per-key for keyed types). Mutates $history in place. This is the incremental
     * unit that buildHistoryFromPreviousLogs applies over all prior logs — exposed so a full
     * recalc can accumulate history in ONE chronological pass (O(n)) instead of rebuilding it from
     * scratch for every log (O(n²)). The fold logic is identical to the former inline loop.
     */
    public function foldMetricsIntoHistory(array &$history, array $metrics, string $family): void
    {
        $descriptors = config("pr_families.families.{$family}", []);

        foreach ($descriptors as $descriptor) {
            $type = $descriptor['type'];
            if (!isset($metrics[$type])) {
                continue;
            }

            $val = $metrics[$type];

            if ($descriptor['compare'] === 'scalarBest') {
                $direction = $descriptor['direction'] ?? 'max';
                $currentBest = $history[$type] ?? null;

                if ($currentBest === null) {
                    $history[$type] = is_array($val) ? $val['value'] : $val;
                } else {
                    $curVal = is_array($val) ? $val['value'] : $val;
                    if ($direction === 'max' && $curVal > $currentBest) {
                        $history[$type] = $curVal;
                    } elseif ($direction === 'min' && $curVal < $currentBest) {
                        $history[$type] = $curVal;
                    }
                }
            } elseif ($descriptor['compare'] === 'keyedBest') {
                $direction = $descriptor['direction'] ?? 'max';
                if (!isset($history[$type]) || !is_array($history[$type])) {
                    $history[$type] = [];
                }

                foreach ($val as $k => $v) {
                    $currentKeyBest = $history[$type][$k] ?? null;
                    if ($currentKeyBest === null) {
                        $history[$type][$k] = $v;
                    } else {
                        if ($direction === 'max' && $v > $currentKeyBest) {
                            $history[$type][$k] = $v;
                        } elseif ($direction === 'min' && $v < $currentKeyBest) {
                            $history[$type][$k] = $v;
                        }
                    }
                }
            }
        }
    }

    /**
     * Shape the engine's raw PR output for one log into persistence-ready PR items (kg de-normalization,
     * rep_count/weight column mapping, unit). This is the exact per-PR shaping detectPRsWithDetails does,
     * WITHOUT the previous_pr_id lookup — recalc rebuilds chains via relinkPreviousPrChains, so that
     * per-PR query is pure waste during a full rebuild. Exposed so recalc can shape PRs from metrics it
     * already computed in its single pass, avoiding a DB re-query per log.
     *
     * @param array $prs    Engine PR output ($result['prs'] from PrEngine::detectPRs)
     * @param string $logUnit The log's set unit ('lbs' | 'kg')
     * @param int $setCount   Number of sets in the log (for the consistency pr_type rep_count)
     * @return array Persistence-ready PR items
     */
    public function shapeDetailedPRs(array $prs, string $logUnit, int $setCount): array
    {
        $detailedPrs = [];

        foreach ($prs as $pr) {
            $val = $pr['value'];
            $prevVal = $pr['previous_value'];
            if (strtolower($logUnit) === 'kg' && in_array($pr['type'], ['one_rm', 'rep_specific', 'volume', 'load'])) {
                if (is_numeric($val)) {
                    $val = round($val / UnitResolver::KG_TO_LBS, 2);
                }
                if (is_numeric($prevVal)) {
                    $prevVal = round($prevVal / UnitResolver::KG_TO_LBS, 2);
                }
            }

            $prItem = [
                'type' => $pr['type'],
                'value' => $val,
                'previous_value' => $prevVal,
                'unit' => $logUnit,
                'previous_pr_id' => null,
            ];

            if (isset($pr['rep_count'])) {
                $prItem['rep_count'] = $pr['rep_count'];
                $prItem['weight'] = $val;
            } elseif ($pr['type'] === 'consistency') {
                $prItem['rep_count'] = $setCount;
            } elseif (isset($pr['key'])) {
                $descriptor = $pr['descriptor'];
                if (($descriptor['keyFields'][0] ?? '') === 'rounds') {
                    $prItem['rep_count'] = (int)$pr['key'];
                } elseif (($descriptor['keyFields'][0] ?? '') === 'time' && $pr['type'] === 'density') {
                    $prItem['rep_count'] = (int)$pr['key'];
                } else {
                    $prItem['weight'] = (float)$pr['key'];
                }
            }

            $detailedPrs[] = $prItem;
        }

        return $detailedPrs;
    }
}
