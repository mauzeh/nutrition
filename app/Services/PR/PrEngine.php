<?php

namespace App\Services\PR;

use App\Models\LiftLog;
use App\Models\PersonalRecord;

// Reason ("why not") records are shaped exclusively by PrReasons so the detect loop below
// stays a clean, line-for-line analogue of the Athlete engine's PR-detection loop.

final class PrEngine
{
    /**
     * Compute metrics for a given log according to its PR family descriptors.
     *
     * @param array|LiftLog $log Log object or array with liftSets
     * @param string $family PR family name
     * @return array Array of metrics keyed by pr_type
     */
    public function computeMetrics(mixed $log, string $family): array
    {
        $descriptors = config("pr_families.families.{$family}", []);
        if (empty($descriptors)) {
            return [];
        }

        $sets = $this->extractSets($log);
        if (empty($sets)) {
            return [];
        }

        $metrics = [];
        foreach ($descriptors as $descriptor) {
            $type = $descriptor['type'];
            $reduced = Reductions::reduce($sets, $descriptor);
            if ($reduced !== null) {
                $metrics[$type] = $reduced;
            }
        }

        return $metrics;
    }

    /**
     * Detect PRs by comparing current metrics against history.
     *
     * STRUCTURAL MIRROR of the Athlete engine's detectMeasurementPRs
     * (athlete/src/shared/logging/pr/measurementPREngine.js): one loop over the family's
     * descriptors → dispatch on `compare` (scalarBest | keyedBest) → `isPR ? push : reason`.
     * Both engines receive `$history` already in the common comparable shape (scalar type → a
     * bare number|null; keyed type → { key => number }). The engines differ only in HOW that
     * shape is produced upstream — Logger derives it from prior logs
     * (PRDetectionService::buildHistoryFromPreviousLogs); Athlete adapts its persisted prHistory
     * blob (normalizeHistory). That difference is the documented, legitimate storage-model split;
     * the loop below is identical to Athlete's.
     *
     * @param array $metrics Metrics computed by computeMetrics
     * @param array $history Comparable bests per type
     * @param string $family PR family name
     * @return array { prs: array, reasons: array }
     */
    public function detectPRs(array $metrics, array $history, string $family): array
    {
        $descriptors = config("pr_families.families.{$family}", []);
        if (empty($descriptors)) {
            return ['prs' => [], 'reasons' => []];
        }

        $prs = [];
        $reasons = [];

        foreach ($descriptors as $descriptor) {
            $type = $descriptor['type'];
            if (!isset($metrics[$type])) {
                continue;
            }

            $currentMetric = $metrics[$type];
            $typeHistory = $history[$type] ?? null;

            if ($descriptor['compare'] === 'scalarBest') {
                $res = Comparators::scalarBest($currentMetric, $typeHistory, $descriptor);
                if ($res['isPR']) {
                    $prs[] = [
                        'type' => $type,
                        'value' => $res['current'],
                        'previous_value' => $res['best'],
                        'descriptor' => $descriptor,
                    ];
                } elseif ($reason = PrReasons::forMiss($descriptor, $res)) {
                    $reasons[] = $reason;
                }
            } elseif ($descriptor['compare'] === 'keyedBest') {
                $bestMap = is_array($typeHistory) ? $typeHistory : [];
                $keyedRes = Comparators::keyedBest($currentMetric, $bestMap, $descriptor);

                foreach ($keyedRes as $key => $res) {
                    if ($res['isPR']) {
                        $prItem = [
                            'type' => $type,
                            'value' => $res['current'],
                            'previous_value' => $res['best'],
                            'descriptor' => $descriptor,
                        ];

                        if (($descriptor['store'] ?? '') === 'keyedByReps') {
                            $prItem['rep_count'] = (int)$key;
                        } else {
                            $prItem['key'] = $key;
                        }

                        $prs[] = $prItem;
                    } elseif ($reason = PrReasons::forMiss($descriptor, $res, $key)) {
                        $reasons[] = $reason;
                    }
                }
            }
        }

        return [
            'prs' => $prs,
            'reasons' => $reasons,
        ];
    }

    /**
     * Resolve the PR family for an exercise. Tries the log_type first, then falls back to the
     * exercise_type when the log_type is absent OR present-but-unmapped (e.g. 'bodyweight-reps' is a
     * real Athlete log_type that isn't a family key on its own — it must resolve via exercise_type
     * 'bodyweight'). A key mapped explicitly to null (banded) means "no PRs" and is preserved.
     * Only a type unknown to BOTH maps falls back to the weightlifting default.
     */
    public function resolveFamily(?string $logType, ?string $exerciseType = null): ?string
    {
        $map = config('pr_families.logTypeToFamily', []);

        foreach ([$logType, $exerciseType] as $candidate) {
            if ($candidate !== null && $candidate !== '' && array_key_exists($candidate, $map)) {
                return $map[$candidate];
            }
        }

        return $map['regular'] ?? 'weightlifting';
    }

    private function extractSets(mixed $log): array
    {
        if ($log instanceof LiftLog) {
            return $log->liftSets->toArray();
        }

        if (is_array($log)) {
            return $log['lift_sets'] ?? $log['liftSets'] ?? [];
        }

        if (is_object($log) && isset($log->liftSets)) {
            return is_array($log->liftSets) ? $log->liftSets : $log->liftSets->toArray();
        }

        return [];
    }
}
