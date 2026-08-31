<?php

namespace App\Services\PR;

use App\Models\LiftLog;
use App\Models\PersonalRecord;

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
     * @param array $metrics Metrics computed by computeMetrics
     * @param array $history History of bests per type
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
                } else {
                    $reasons[] = [
                        'type' => $type,
                        'current' => $res['current'],
                        'best' => $res['best'],
                        'direction' => $descriptor['direction'] ?? 'max',
                        'tolerance' => $descriptor['tolerance'] ?? 'none',
                        'deltaToBeat' => $res['best'] !== null ? $res['best'] - $res['current'] : null,
                    ];
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
                    } else {
                        $reasons[] = [
                            'type' => $type,
                            'key' => $key,
                            'current' => $res['current'],
                            'best' => $res['best'],
                            'direction' => $descriptor['direction'] ?? 'max',
                            'tolerance' => $descriptor['tolerance'] ?? 'none',
                            'deltaToBeat' => $res['best'] !== null ? $res['best'] - $res['current'] : null,
                        ];
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
     * Resolve PR family for a given log type string.
     */
    public function resolveFamily(?string $logType, ?string $exerciseType = null): ?string
    {
        $type = $logType ?: ($exerciseType ?: 'regular');
        return config("pr_families.logTypeToFamily.{$type}", config("pr_families.logTypeToFamily.regular"));
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
