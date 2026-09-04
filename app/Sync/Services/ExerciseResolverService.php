<?php

namespace App\Sync\Services;

use App\Models\Exercise;
use App\Models\ExerciseAlias;
use App\Models\User;
use Illuminate\Support\Str;

class ExerciseResolverService
{
    /**
     * Resolve an exercise name to an Exercise model.
     */
    public function resolve(string $exerciseName, User $user, ?string $logType = null, ?string $canonicalName = null): Exercise
    {
        $canonical = $canonicalName ?: Str::snake($exerciseName);

        // 1. Exact match on exercises.canonical_name (scoped to global only)
        $exercise = Exercise::query()
            ->whereNull('user_id')
            ->where('canonical_name', $canonical)
            ->first();

        if ($exercise) {
            $updates = [];
            if ($exercise->title !== $exerciseName) {
                $updates['title'] = $exerciseName;
            }
            // Populate log_type if not already set and we have one from the sync payload
            if ($logType && !$exercise->log_type) {
                $updates['log_type'] = $logType;
            }
            if (!empty($updates)) {
                $exercise->update($updates);
            }

            return $exercise;
        }

        // 2. Case-insensitive match on exercises.title (scoped to global only)
        $exercise = Exercise::query()
            ->whereNull('user_id')
            ->whereRaw('LOWER(title) = ?', [strtolower($exerciseName)])
            ->first();

        if ($exercise) {
            $updates = [];
            if ($exercise->title !== $exerciseName) {
                $updates['title'] = $exerciseName;
            }
            if ($logType && !$exercise->log_type) {
                $updates['log_type'] = $logType;
            }
            if (!empty($updates)) {
                $exercise->update($updates);
            }

            return $exercise;
        }

        // 3. Case-insensitive match on exercise_aliases.alias_name (scoped to global only)
        $alias = ExerciseAlias::query()
            ->whereNull('user_id')
            ->whereRaw('LOWER(alias_name) = ?', [strtolower($exerciseName)])
            ->first();

        if ($alias) {
            $exercise = $alias->exercise;
            if ($exercise && $exercise->user_id === null) {
                $updates = [];
                if ($exercise->title !== $exerciseName) {
                    $updates['title'] = $exerciseName;
                }
                if ($logType && !$exercise->log_type) {
                    $updates['log_type'] = $logType;
                }
                if (!empty($updates)) {
                    $exercise->update($updates);
                }

                return $exercise;
            }
        }

        // 4. Auto-create new exercise or promote user-owned if canonical_name conflict exists
        $existingUserOwned = Exercise::query()
            ->whereNotNull('user_id')
            ->where('canonical_name', $canonical)
            ->first();

        if ($existingUserOwned) {
            $updates = ['user_id' => null];
            if ($existingUserOwned->title !== $exerciseName) {
                $updates['title'] = $exerciseName;
            }
            if ($logType && !$existingUserOwned->log_type) {
                $updates['log_type'] = $logType;
            }
            $existingUserOwned->update($updates);

            return $existingUserOwned;
        }

        $derivedType = $this->deriveExerciseType($logType);

        return Exercise::create([
            'title' => $exerciseName,
            'canonical_name' => $canonical,
            'exercise_type' => $derivedType,
            'log_type' => $logType,
            'user_id' => null,
            'show_in_feed' => true,
        ]);
    }

    /**
     * Derive exercise type from log type.
     */
    private function deriveExerciseType(?string $logType): string
    {
        if (! $logType) {
            return 'regular';
        }

        return match ($logType) {
            'barbell', 'single-dumbbell', 'dual-dumbbell', 'kettlebell', 'ball', 'machine' => 'regular',
            'weighted-carry', 'sled', 'weighted-carry-1-kb', 'weighted-carry-2-kb', 'weighted-carry-1-db', 'weighted-carry-2-db', 'weighted-carry-ball' => 'load_output',
            'static-hold' => 'static_hold',
            'bodyweight', 'bodyweight-reps', 'added-weight' => 'bodyweight',
            'banded' => 'banded_resistance',
            'cardio', 'cardio-calories', 'cardio-distance' => 'cardio',
            default => 'regular',
        };
    }
}
