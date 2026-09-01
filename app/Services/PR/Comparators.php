<?php

namespace App\Services\PR;

final class Comparators
{
    /**
     * Determine tolerance float value based on tolerance type and values.
     */
    public static function resolveTolerance(string $toleranceType, float|int $best): float
    {
        return match ($toleranceType) {
            'unit' => 0.1,
            'percent' => $best * 0.01,
            'distance' => 0.0, // distance PRs use strict > comparison in logger
            'none' => 0.0,
            default => 0.0,
        };
    }

    /**
     * Scalar comparison.
     *
     * @param float|int|null $current
     * @param float|int|null $best
     * @param array $descriptor
     * @return array { isPR: bool, delta: float|int|null, current: float|int|null, best: float|int|null }
     */
    public static function scalarBest(float|int|null $current, float|int|null $best, array $descriptor): array
    {
        if ($current === null || $current <= 0) {
            return [
                'isPR' => false,
                'current' => $current,
                'best' => $best,
                'delta' => null,
            ];
        }

        if ($best === null) {
            return [
                'isPR' => true,
                'current' => $current,
                'best' => null,
                'delta' => $current,
            ];
        }

        $direction = $descriptor['direction'] ?? 'max';
        $toleranceType = $descriptor['tolerance'] ?? 'none';
        $tol = self::resolveTolerance($toleranceType, (float)$best);

        $isPR = match ($direction) {
            'max' => $current > ($best + $tol),
            'min' => $current < ($best - $tol),
            default => false,
        };

        $delta = $current - $best;

        return [
            'isPR' => $isPR,
            'current' => $current,
            'best' => $best,
            'delta' => $delta,
        ];
    }

    /**
     * Keyed comparison.
     *
     * @param array $currentMetrics Keyed map of current metrics (e.g. ['5' => 100, '3' => 120])
     * @param array $bestHistory Keyed map of stored bests (e.g. ['5' => 95, '3' => 120])
     * @param array $descriptor
     * @return array Keyed array of results per key
     */
    public static function keyedBest(array $currentMetrics, array $bestHistory, array $descriptor): array
    {
        $results = [];
        $direction = $descriptor['direction'] ?? 'max';
        $toleranceType = $descriptor['tolerance'] ?? 'none';
        $suppressDominated = $descriptor['suppressDominated'] ?? false;

        foreach ($currentMetrics as $key => $currentVal) {
            if ($currentVal === null || $currentVal <= 0) {
                continue;
            }

            // Keys are integers on both engines (rep counts; weight/time buckets are whole units),
            // so an exact key lookup is sufficient — mirror of the Athlete keyedBest.
            $bestVal = $bestHistory[$key] ?? null;
            $requirePrevious = $descriptor['requirePrevious'] ?? false;

            if ($bestVal === null) {
                $results[$key] = [
                    'isPR' => !$requirePrevious,
                    'current' => $currentVal,
                    'best' => null,
                    'delta' => $currentVal,
                ];
            } else {
                $tol = self::resolveTolerance($toleranceType, (float)$bestVal);

                $isPR = match ($direction) {
                    'max' => $currentVal > ($bestVal + $tol),
                    'min' => $currentVal < ($bestVal - $tol),
                    default => false,
                };

                $results[$key] = [
                    'isPR' => $isPR,
                    'current' => $currentVal,
                    'best' => $bestVal,
                    'delta' => $currentVal - $bestVal,
                ];
            }
        }

        // Apply rep-specific dominated-by-higher-reps suppression if enabled
        if ($suppressDominated && !empty($results)) {
            $results = self::applyDominatedSuppression($results, $currentMetrics, $bestHistory, $descriptor);
        }

        return $results;
    }

    /**
     * Suppress rep-specific PR if a higher rep count in current or history already holds >= weight.
     */
    private static function applyDominatedSuppression(array $results, array $currentMetrics, array $bestHistory, array $descriptor): array
    {
        // Tolerance-aware: a higher rep count dominates when it holds >= (thisWeight - tol) — mirror
        // of the Athlete applyDominatedSuppression (a within-tolerance heavier-for-more-reps set wins).
        $tol = self::resolveTolerance($descriptor['tolerance'] ?? 'none', 0);

        foreach ($results as $key => $res) {
            if (!$res['isPR']) {
                continue;
            }

            $repCount = (int)$key;
            $currentWeight = $res['current'];

            // 1. Check current metrics higher reps
            foreach ($currentMetrics as $otherKey => $otherWeight) {
                if ((int)$otherKey > $repCount && $otherWeight >= $currentWeight - $tol) {
                    $results[$key]['isPR'] = false;
                    break;
                }
            }

            if (!$results[$key]['isPR']) {
                continue;
            }

            // 2. Check stored history higher reps
            foreach ($bestHistory as $otherKey => $otherWeight) {
                if ((int)$otherKey > $repCount && $otherWeight >= $currentWeight - $tol) {
                    $results[$key]['isPR'] = false;
                    break;
                }
            }
        }

        return $results;
    }
}
