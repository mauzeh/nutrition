<?php

namespace App\Sync\Controllers;

use App\Models\LiftLog;
use App\Models\PersonalRecord;
use App\Sync\Models\AthleteBlueprint;
use App\Sync\Models\AthletePreference;
use App\Sync\Services\SetFieldMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RestoreController
{
    public function __construct(
        private SetFieldMapper $setFieldMapper,
    ) {}

    /**
     * Restore the athlete's full state.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // 1. Fetch blueprint
        $blueprint = AthleteBlueprint::where('user_id', $user->id)->first();

        // 2. Fetch preferences
        $preference = AthletePreference::where('user_id', $user->id)->first();

        // 3. Fetch logs (newest first so today's logs arrive in early batches)
        $liftLogs = LiftLog::with(['exercise', 'liftSets'])
            ->where('user_id', $user->id)
            ->orderBy('logged_at', 'desc')
            ->get();

        $logsData = [];
        foreach ($liftLogs as $liftLog) {
            if (!$liftLog->exercise) {
                continue;
            }

            // Determine log type
            $logType = $liftLog->log_type;
            if (!$logType) {
                // Prefer the exercise's canonical log_type from Athlete sync
                $logType = $liftLog->exercise->log_type;
            }
            if (!$logType) {
                // Final fallback: derive from coarse exercise_type
                $exerciseType = $liftLog->exercise->exercise_type ?? 'regular';
                $logType = match ($exerciseType) {
                    'cardio' => 'cardio',
                    'static_hold', 'static-hold' => 'static-hold',
                    'banded_resistance', 'banded_assistance', 'banded' => 'banded',
                    'bodyweight' => 'bodyweight-reps',
                    default => 'barbell',
                };
            }

            // Skip logs with no live sets: they carry no unit and would otherwise
            // require a fabricated fallback in the payload.
            $firstSet = $liftLog->liftSets->first();
            if (! $firstSet) {
                continue;
            }

            // Map sets
            $sets = [];
            foreach ($liftLog->liftSets as $set) {
                $sets[] = $this->setFieldMapper->mapFromColumns($logType, $set);
            }

            $logData = [
                'id' => $liftLog->id,
                'exerciseId' => $liftLog->exercise->canonical_name,
                'exerciseName' => $liftLog->exercise->title,
                'date' => $liftLog->logged_at->toDateString(),
                'logType' => $logType,
                'sets' => $sets,
                'note' => $liftLog->comments,
                'weightUnit' => $firstSet->unit,
            ];

            if ($liftLog->track !== null) {
                $logData['track'] = $liftLog->track;
            }
            if ($liftLog->block_index !== null) {
                $logData['blockIndex'] = (int) $liftLog->block_index;
            }
            if ($liftLog->movement_index !== null) {
                $logData['movementIndex'] = (int) $liftLog->movement_index;
            }

            $logsData[] = $logData;
        }

        // 4. Fetch PR history
        $records = PersonalRecord::current()
            ->where('user_id', $user->id)
            ->with('exercise')
            ->get();

        $prHistory = [];
        foreach ($records as $record) {
            if (!$record->exercise) {
                continue;
            }
            $canonical = $record->exercise->canonical_name;

            if (!isset($prHistory[$canonical])) {
                $prHistory[$canonical] = [];
            }

            $prHistory[$canonical][] = [
                'pr_type' => $record->pr_type,
                'value' => (float) $record->value,
                'rep_count' => $record->rep_count !== null ? (int) $record->rep_count : null,
                'weight' => $record->weight !== null ? (float) $record->weight : null,
            ];
        }

        return response()->json([
            'status' => 'ok',
            'blueprint' => $blueprint ? $blueprint->blueprint_data : null,
            'preferences' => $preference ? $preference->preferences_data : null,
            'logs' => $logsData,
            'prHistory' => (object) $prHistory,
            'userExercises' => $this->getUserExercises($user),
        ]);
    }

    /**
     * Get user-scoped exercises for the restore payload.
     * Returns exercises the user created (user_id = current user).
     */
    private function getUserExercises($user): array
    {
        $exercises = \App\Models\Exercise::where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->orderBy('title', 'asc')
            ->get();

        return $exercises->map(function ($exercise) {
            return [
                'id' => $exercise->canonical_name,
                'name' => $exercise->title,
                'logType' => $exercise->log_type ?? 'barbell',
                'exerciseType' => $exercise->exercise_type ?? 'regular',
            ];
        })->values()->toArray();
    }
}
