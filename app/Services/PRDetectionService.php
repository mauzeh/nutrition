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
        $exercise = $liftLog->exercise;
        $user = $liftLog->user;
        $family = $this->prEngine->resolveFamily($exercise->log_type, $exercise->exercise_type);

        if (!$family) {
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

        $detailedPrs = [];

        $logUnit = $liftLog->liftSets->first()->unit ?? 'lbs';

        foreach ($result['prs'] as $pr) {
            $val = $pr['value'];
            $prevVal = $pr['previous_value'];
            // Mass-dimensioned PR values are de-normalized back to the log unit. NOT hypertrophy —
            // its value is a REP COUNT (dimensionless, like density's set count), so converting it
            // by the kg factor produced nonsense (e.g. 9 reps → 4.08). one_rm / rep_specific /
            // volume / load all carry a mass value (load = heaviest load_output weight).
            if (strtolower($logUnit) === 'kg' && in_array($pr['type'], ['one_rm', 'rep_specific', 'volume', 'load'])) {
                if (is_numeric($val)) {
                    $val = round($val / 2.2046226218, 2);
                }
                if (is_numeric($prevVal)) {
                    $prevVal = round($prevVal / 2.2046226218, 2);
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
                $prItem['rep_count'] = $liftLog->liftSets->count();
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

            // Enrich previous_pr_id if a previous record existed
            if ($pr['previous_value'] !== null) {
                $prevPrQuery = PersonalRecord::where('user_id', $user->id)
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
                    $prItem['previous_pr_id'] = $prevRecord->id;
                }
            }

            $detailedPrs[] = $prItem;
        }

        return $detailedPrs;
    }

    private function buildHistoryFromPreviousLogs(Collection $previousLogs, Exercise $exercise, string $family): array
    {
        $history = [];
        $descriptors = config("pr_families.families.{$family}", []);

        foreach ($previousLogs as $prevLog) {
            $prevMetrics = $this->prEngine->computeMetrics($prevLog, $family);

            foreach ($descriptors as $descriptor) {
                $type = $descriptor['type'];
                if (!isset($prevMetrics[$type])) {
                    continue;
                }

                $val = $prevMetrics[$type];

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

        return $history;
    }
}
