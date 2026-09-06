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
                'options' => [
                    ['value' => 'm', 'label' => 'm'],
                    ['value' => 'ft', 'label' => 'ft'],
                ],
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
