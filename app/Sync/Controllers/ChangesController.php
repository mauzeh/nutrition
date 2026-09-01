<?php

namespace App\Sync\Controllers;

use App\Models\LiftLog;
use App\Sync\Services\SetFieldMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChangesController
{
    public function __construct(
        private SetFieldMapper $setFieldMapper,
        private \App\Sync\Services\RehydrationService $rehydrationService,
    ) {}

    /**
     * Return all logs for the authenticated user.
     * Used by the Athlete app to pull changes made on Logger.
     *
     * GET /api/sync/changes
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Fetch all logs for this user
        $liftLogs = LiftLog::with(['exercise', 'liftSets'])
            ->where('user_id', $user->id)
            ->orderBy('logged_at', 'asc')
            ->get();

        $logsData = [];
        foreach ($liftLogs as $liftLog) {
            if (! $liftLog->exercise) {
                continue;
            }

            $logType = $liftLog->log_type;
            if (! $logType) {
                // Prefer the exercise's canonical log_type from Athlete sync
                $logType = $liftLog->exercise->log_type;
            }
            if (! $logType) {
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
                'updated_at' => $liftLog->updated_at->toIso8601String(),
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

        // Also include soft-deleted log IDs so client can remove them
        $deletedLogs = LiftLog::onlyTrashed()
            ->where('user_id', $user->id)
            ->pluck('id')
            ->toArray();

        $payload = [
            'status' => 'ok',
            'logs' => $logsData,
            'deleted_ids' => $deletedLogs,
            'userExercises' => $this->getUserExercises($user),
        ];

        $token = $this->rehydrationService->latestToken($user);
        if ($token !== null) {
            $payload['rehydrate'] = [
                'token' => $token,
                'reason' => $this->rehydrationService->latestReason($user) ?? 'exercise-merge',
            ];
        }

        return response()->json($payload);
    }

    /**
     * Get user-scoped exercises for the changes payload.
     * Returns exercises the user created (user_id = current user, not deleted).
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
