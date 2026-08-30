<?php

namespace App\Services\ExerciseTypes;

use App\Models\LiftLog;
use App\Models\LiftSet;
use App\Models\PersonalRecord;
use App\Models\User;

class LoadOutputExerciseType extends BaseExerciseType
{
    public const FT_TO_METERS = 0.3048;

    public function getTypeName(): string
    {
        return 'load_output';
    }

    public static function normalizeDistanceToMeters(float|int $distance, string $unit): int
    {
        if ($distance <= 0) {
            return 0;
        }

        if ($unit === 'ft') {
            return (int) round($distance * self::FT_TO_METERS);
        }

        return (int) round($distance);
    }

    public function processLiftData(array $data): array
    {
        $processedData = $data;
        $processedData['band_color'] = null;
        $processedData['reps'] = null;

        return $processedData;
    }

    public function processExerciseData(array $data): array
    {
        $processedData = $data;
        $processedData['exercise_type'] = 'load_output';
        $processedData['is_bodyweight'] = false;

        return $processedData;
    }

    public function formatWeightDisplay(LiftLog $liftLog): string
    {
        $firstSet = $liftLog->relationLoaded('liftSets')
            ? $liftLog->liftSets->first()
            : $liftLog->liftSets()->first();

        if (!$firstSet) {
            return '0 lbs';
        }

        $weight = (float) $firstSet->weight;
        $unit = $firstSet->unit ?? 'lbs';

        if ($weight <= 0) {
            return 'BW';
        }

        return $weight . ' ' . $unit;
    }

    public function formatSingleSetBadge(LiftSet $set, ?User $user = null): string
    {
        $weight = (float) $set->weight;
        $unit = $set->unit ?? 'lbs';

        if ($weight <= 0) {
            return 'BW';
        }

        return $this->unitResolver()->formatForUser($weight, $unit, $user);
    }

    protected function getSetEffortValue(LiftSet $set): string
    {
        $parts = [];

        $distance = (float) ($set->distance ?? 0);
        $distUnit = $set->distance_unit ?? 'm';
        if ($distance > 0) {
            $distDisplay = ($distance == (int) $distance) ? (int) $distance : $distance;
            $parts[] = $distDisplay . ' ' . $distUnit;
        }

        $time = (int) ($set->time ?? 0);
        if ($time > 0) {
            $parts[] = $this->formatDuration($time);
        }

        return !empty($parts) ? implode(' / ', $parts) : '0 m';
    }

    protected function formatUniformRepsSets(int $count, string $effort): string
    {
        return $count . ' x ' . $effort;
    }

    public function formatCompleteDisplay(LiftLog $liftLog): string
    {
        $weightDisplay = $this->formatWeightDisplay($liftLog);

        $firstSet = $liftLog->relationLoaded('liftSets')
            ? $liftLog->liftSets->first()
            : $liftLog->liftSets()->first();

        if (!$firstSet) {
            return $weightDisplay;
        }

        $parts = [$weightDisplay];

        $distance = (float) ($firstSet->distance ?? 0);
        $distUnit = $firstSet->distance_unit ?? 'm';
        if ($distance > 0) {
            $distDisplay = ($distance == (int) $distance) ? (int) $distance : $distance;
            $parts[] = $distDisplay . $distUnit;
        }

        $time = (int) ($firstSet->time ?? 0);
        if ($time > 0) {
            $parts[] = $this->formatDuration($time);
        }

        return implode(' × ', $parts);
    }

    public function getFormFieldDefinitions(array $defaults = [], ?User $user = null): array
    {
        return [
            [
                'name' => 'weight',
                'label' => 'Weight (lbs):',
                'type' => 'numeric',
                'defaultValue' => $defaults['weight'] ?? 90,
                'increment' => 5,
                'min' => 0,
                'max' => 2000,
            ],
            [
                'name' => 'distance',
                'label' => 'Distance:',
                'type' => 'numeric',
                'defaultValue' => $defaults['distance'] ?? 50,
                'increment' => 5,
                'min' => 0,
                'max' => 999,
            ],
            [
                'name' => 'distance_unit',
                'label' => 'Unit:',
                'type' => 'select',
                'defaultValue' => $defaults['distance_unit'] ?? 'm',
                'options' => ['m' => 'm', 'ft' => 'ft'],
            ],
            [
                'name' => 'time',
                'label' => 'Time (seconds):',
                'type' => 'numeric',
                'defaultValue' => $defaults['time'] ?? 30,
                'increment' => 1,
                'min' => 1,
                'max' => 900,
            ],
        ];
    }

    public function formatLoggedItemDisplay(LiftLog $liftLog): string
    {
        return $this->formatCompleteDisplay($liftLog);
    }

    public function formatFormMessageDisplay(array $lastSession): string
    {
        $weight = $lastSession['weight'] ?? 0;
        $distance = $lastSession['distance'] ?? 0;
        $unit = $lastSession['distance_unit'] ?? 'm';
        $time = $lastSession['time'] ?? 0;

        $parts = ["{$weight} lbs"];
        if ($distance > 0) {
            $parts[] = "{$distance}{$unit}";
        }
        if ($time > 0) {
            $parts[] = $this->formatDuration((int) $time);
        }

        return implode(' × ', $parts);
    }

    public function formatTableCellDisplay(LiftLog $liftLog): array
    {
        return [
            'primary' => $this->formatCompleteDisplay($liftLog),
        ];
    }

    public function format1RMTableCellDisplay(LiftLog $liftLog): string
    {
        return 'N/A';
    }

    public function getTypeDisplayInfo(): array
    {
        return [
            'icon' => 'fas fa-truck-loading',
            'name' => 'Load Output',
        ];
    }

    public function getChartTitle(): string
    {
        return 'Load Output Progress';
    }

    public function formatProgressionSuggestion(LiftLog $liftLog): ?string
    {
        $firstSet = $liftLog->relationLoaded('liftSets')
            ? $liftLog->liftSets->first()
            : $liftLog->liftSets()->first();

        if (!$firstSet) {
            return null;
        }

        $weight = (float) $firstSet->weight;
        $distance = (float) ($firstSet->distance ?? 0);
        $unit = $firstSet->distance_unit ?? 'm';

        $newWeight = $weight + 5;

        return "Try {$newWeight} lbs × {$distance}{$unit}";
    }

    public function formatSuccessMessageDescription(?float $weight, int $reps, int $rounds, ?string $bandColor = null): string
    {
        $weightDisplay = $weight ? "{$weight} lbs" : 'BW';
        $setsText = $rounds == 1 ? '1 set' : "{$rounds} sets";

        return "{$weightDisplay} × {$setsText}";
    }

    public function getSupportedPRTypes(): array
    {
        return [
            \App\Enums\PRType::LOAD,
            \App\Enums\PRType::DISTANCE,
            \App\Enums\PRType::DURATION,
            \App\Enums\PRType::SPEED,
        ];
    }

    public function calculateCurrentMetrics(LiftLog $liftLog): array
    {
        $maxLoad = 0.0;
        $maxDistance = 0;
        $maxDuration = 0;
        $speedBuckets = [];

        foreach ($liftLog->liftSets as $set) {
            $weight = (float) ($set->weight ?? 0);
            $unit = $set->unit ?? 'lbs';

            $loadComp = $weight > 0 ? round($this->unitResolver()->convert($weight, $unit, 'lbs'), 4) : 0.0;
            $loadStr = ($loadComp == (int) $loadComp) ? (string) (int) $loadComp : (string) $loadComp;

            $distance = self::normalizeDistanceToMeters(
                (float) ($set->distance ?? 0),
                $set->distance_unit ?? 'm'
            );
            $duration = (int) ($set->time ?? 0);

            if ($loadComp > $maxLoad) {
                $maxLoad = $loadComp;
            }
            if ($distance > $maxDistance) {
                $maxDistance = $distance;
            }
            if ($duration > $maxDuration) {
                $maxDuration = $duration;
            }

            if ($loadComp > 0 && $distance > 0 && $duration > 0) {
                $bucketKey = "{$loadStr}|{$distance}";
                if (!isset($speedBuckets[$bucketKey]) || $duration < $speedBuckets[$bucketKey]) {
                    $speedBuckets[$bucketKey] = $duration;
                }
            }
        }

        return [
            'load' => $maxLoad,
            'distance' => $maxDistance,
            'duration' => $maxDuration,
            'speedBuckets' => $speedBuckets,
        ];
    }

    private function beats(float|int $current, float|int $stored, string $direction): bool
    {
        if ($direction === 'max') {
            return $current > $stored;
        }

        return $current < $stored;
    }

    public function compareToPrevious(array $currentMetrics, \Illuminate\Database\Eloquent\Collection $previousLogs, LiftLog $currentLog): array
    {
        $prs = [];

        $prevLoad = 0.0;
        $prevLoadLogId = null;
        $prevDistance = 0;
        $prevDistanceLogId = null;
        $prevDuration = 0;
        $prevDurationLogId = null;
        $prevSpeedBuckets = [];

        foreach ($previousLogs as $log) {
            foreach ($log->liftSets as $set) {
                $weight = (float) ($set->weight ?? 0);
                $unit = $set->unit ?? 'lbs';
                $loadComp = $weight > 0 ? round($this->unitResolver()->convert($weight, $unit, 'lbs'), 4) : 0.0;
                $loadStr = ($loadComp == (int) $loadComp) ? (string) (int) $loadComp : (string) $loadComp;

                $distance = self::normalizeDistanceToMeters(
                    (float) ($set->distance ?? 0),
                    $set->distance_unit ?? 'm'
                );
                $duration = (int) ($set->time ?? 0);

                if ($loadComp > $prevLoad) {
                    $prevLoad = $loadComp;
                    $prevLoadLogId = $log->id;
                }

                if ($distance > $prevDistance) {
                    $prevDistance = $distance;
                    $prevDistanceLogId = $log->id;
                }

                if ($duration > $prevDuration) {
                    $prevDuration = $duration;
                    $prevDurationLogId = $log->id;
                }

                if ($loadComp > 0 && $distance > 0 && $duration > 0) {
                    $bucketKey = "{$loadStr}|{$distance}";
                    if (!isset($prevSpeedBuckets[$bucketKey]) || $duration < $prevSpeedBuckets[$bucketKey]['duration']) {
                        $prevSpeedBuckets[$bucketKey] = [
                            'duration' => $duration,
                            'log_id' => $log->id,
                        ];
                    }
                }
            }
        }

        if ($previousLogs->isEmpty()) {
            if ($currentMetrics['load'] > 0) {
                $prs[] = [
                    'type' => 'load',
                    'value' => $currentMetrics['load'],
                    'previous_value' => null,
                    'previous_lift_log_id' => null,
                    'unit' => 'lbs',
                ];
            }
            if ($currentMetrics['distance'] > 0) {
                $prs[] = [
                    'type' => 'distance',
                    'value' => $currentMetrics['distance'],
                    'previous_value' => null,
                    'previous_lift_log_id' => null,
                    'unit' => 'm',
                ];
            }
            if ($currentMetrics['duration'] > 0) {
                $prs[] = [
                    'type' => 'duration',
                    'value' => $currentMetrics['duration'],
                    'previous_value' => null,
                    'previous_lift_log_id' => null,
                    'unit' => 's',
                ];
            }

            return $prs;
        }

        if ($currentMetrics['load'] > 0 && $this->beats($currentMetrics['load'], $prevLoad, 'max')) {
            $prs[] = [
                'type' => 'load',
                'value' => $currentMetrics['load'],
                'previous_value' => $prevLoad > 0 ? $prevLoad : null,
                'previous_lift_log_id' => $prevLoadLogId,
                'unit' => 'lbs',
            ];
        }

        if ($currentMetrics['distance'] > 0 && $this->beats($currentMetrics['distance'], $prevDistance, 'max')) {
            $prs[] = [
                'type' => 'distance',
                'value' => $currentMetrics['distance'],
                'previous_value' => $prevDistance > 0 ? $prevDistance : null,
                'previous_lift_log_id' => $prevDistanceLogId,
                'unit' => 'm',
            ];
        }

        if ($currentMetrics['duration'] > 0 && $this->beats($currentMetrics['duration'], $prevDuration, 'max')) {
            $prs[] = [
                'type' => 'duration',
                'value' => $currentMetrics['duration'],
                'previous_value' => $prevDuration > 0 ? $prevDuration : null,
                'previous_lift_log_id' => $prevDurationLogId,
                'unit' => 's',
            ];
        }

        foreach ($currentMetrics['speedBuckets'] as $bucketKey => $curDuration) {
            if (isset($prevSpeedBuckets[$bucketKey])) {
                $stored = $prevSpeedBuckets[$bucketKey]['duration'];
                if ($this->beats($curDuration, $stored, 'min')) {
                    [$loadStr, $integerMeters] = explode('|', $bucketKey);
                    $prs[] = [
                        'type' => 'speed',
                        'value' => $curDuration,
                        'weight' => (float) $loadStr,
                        'unit' => 'lbs',
                        'rep_count' => (int) $integerMeters,
                        'previous_value' => $stored,
                        'previous_lift_log_id' => $prevSpeedBuckets[$bucketKey]['log_id'],
                    ];
                }
            }
        }

        return $prs;
    }

    public function formatPRDisplay(PersonalRecord $pr, LiftLog $liftLog): array
    {
        return match ($pr->pr_type) {
            'load' => [
                'label' => 'Heaviest Load',
                'value' => $pr->previous_value ? ((float) $pr->previous_value) . ' lbs' : '-',
                'comparison' => ((float) $pr->value) . ' lbs',
            ],
            'distance' => [
                'label' => 'Farthest Distance',
                'value' => $pr->previous_value ? ((int) $pr->previous_value) . 'm' : '-',
                'comparison' => ((int) $pr->value) . 'm',
            ],
            'duration' => [
                'label' => 'Longest Duration',
                'value' => $pr->previous_value ? $this->formatDuration((int) $pr->previous_value) : '-',
                'comparison' => $this->formatDuration((int) $pr->value),
            ],
            'speed' => [
                'label' => "Fastest {$pr->weight} lbs × {$pr->rep_count} m",
                'value' => $pr->previous_value ? $this->formatDuration((int) $pr->previous_value) : '-',
                'comparison' => $this->formatDuration((int) $pr->value),
            ],
            default => [
                'label' => ucfirst(str_replace('_', ' ', $pr->pr_type)),
                'value' => $pr->previous_value ? (string) $pr->previous_value : '-',
                'comparison' => (string) $pr->value,
            ],
        };
    }

    public function formatCurrentPRDisplay(PersonalRecord $pr, LiftLog $liftLog, bool $isCurrent): array
    {
        return match ($pr->pr_type) {
            'load' => [
                'label' => 'Heaviest Load',
                'value' => ((float) $pr->value) . ' lbs',
                'is_current' => $isCurrent,
            ],
            'distance' => [
                'label' => 'Farthest Distance',
                'value' => ((int) $pr->value) . 'm',
                'is_current' => $isCurrent,
            ],
            'duration' => [
                'label' => 'Longest Duration',
                'value' => $this->formatDuration((int) $pr->value),
                'is_current' => $isCurrent,
            ],
            'speed' => [
                'label' => "Fastest {$pr->weight} lbs × {$pr->rep_count} m",
                'value' => $this->formatDuration((int) $pr->value),
                'is_current' => $isCurrent,
            ],
            default => [
                'label' => ucfirst(str_replace('_', ' ', $pr->pr_type)),
                'value' => (string) $pr->value,
                'is_current' => $isCurrent,
            ],
        };
    }

    public function comparisonValue(PersonalRecord $pr, array $currentMetrics, LiftLog $liftLog): ?string
    {
        return match ($pr->pr_type) {
            'load' => isset($currentMetrics['load']) && $currentMetrics['load'] > 0
                ? $currentMetrics['load'] . ' lbs'
                : null,
            'distance' => isset($currentMetrics['distance']) && $currentMetrics['distance'] > 0
                ? $currentMetrics['distance'] . 'm'
                : null,
            'duration' => isset($currentMetrics['duration']) && $currentMetrics['duration'] > 0
                ? $this->formatDuration((int) $currentMetrics['duration'])
                : null,
            'speed' => (function() use ($pr, $currentMetrics) {
                if (!isset($pr->weight, $pr->rep_count)) {
                    return null;
                }
                $weight = (float) $pr->weight;
                $loadStr = ($weight == (int) $weight) ? (string) (int) $weight : (string) $weight;
                $key = "{$loadStr}|{$pr->rep_count}";
                return isset($currentMetrics['speedBuckets'][$key])
                    ? $this->formatDuration((int) $currentMetrics['speedBuckets'][$key])
                    : null;
            })(),
            default => null,
        };
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($remainingSeconds === 0) {
            return "{$minutes}m";
        }

        return "{$minutes}m {$remainingSeconds}s";
    }
}
