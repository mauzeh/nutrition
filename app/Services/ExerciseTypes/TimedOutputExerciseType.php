<?php

namespace App\Services\ExerciseTypes;

use App\Models\LiftLog;
use App\Models\LiftSet;
use App\Models\User;

class TimedOutputExerciseType extends BaseExerciseType
{
    public function getTypeName(): string
    {
        return 'timed_output';
    }

    public function processLiftData(array $data): array
    {
        $processedData = $data;
        $processedData['band_color'] = null;

        return $processedData;
    }

    public function processExerciseData(array $data): array
    {
        $processedData = $data;
        $processedData['exercise_type'] = 'timed_output';

        return $processedData;
    }

    public function formatWeightDisplay(LiftLog $liftLog): string
    {
        $firstSet = $liftLog->relationLoaded('liftSets')
            ? $liftLog->liftSets->first()
            : $liftLog->liftSets()->first();

        if (!$firstSet) {
            return '';
        }

        $time = (int) ($firstSet->time ?? 0);
        if ($time <= 0) {
            return '';
        }

        return $this->formatDuration($time);
    }

    public function formatSingleSetBadge(LiftSet $set, ?User $user = null): string
    {
        $duration = (int) ($set->time ?? 0);

        if ($duration <= 0) {
            return '';
        }

        return $this->formatDuration($duration);
    }

    public function canCalculate1RM(): bool
    {
        return false;
    }

    public function format1RMTableCellDisplay(LiftLog $liftLog): string
    {
        return 'N/A';
    }

    public function getTypeDisplayInfo(): array
    {
        return [
            'icon' => 'fas fa-stopwatch',
            'name' => 'Timed Output',
        ];
    }

    public function getChartTitle(): string
    {
        return 'Duration Progress';
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
