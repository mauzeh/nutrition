<?php

namespace App\Sync\Actions;

use App\Events\LiftLogCompleted;
use App\Models\LiftLog;
use App\Models\User;
use App\Sync\Services\ExerciseResolverService;
use App\Sync\Services\SetFieldMapper;
use App\Sync\Services\SetGroupRuleValidator;
use Carbon\Carbon;

class StoreSyncLogAction
{
    public function __construct(
        private ExerciseResolverService $exerciseResolver,
        private SetFieldMapper $setFieldMapper,
        private SetGroupRuleValidator $setGroupRuleValidator,
    ) {}

    /**
     * Execute the action to store a sync log.
     */
    public function execute(User $user, array $validated, ?string $deviceId): LiftLog
    {
        // Handle idempotency
        if (! empty($validated['idempotency_key'])) {
            $existing = LiftLog::where('user_id', $user->id)
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        // Resolve the exercise
        $exercise = $this->exerciseResolver->resolve(
            $validated['exercise_name'],
            $user,
            $validated['log_type'],
            $validated['canonical_name'] ?? null
        );

        // Enforce group_rule on sets
        $this->setGroupRuleValidator->validateSets($exercise->exercise_type, $validated['sets'] ?? []);

        // Parse logged_at date at 12:00:00
        $loggedAt = Carbon::parse($validated['date'])->setTime(12, 0, 0);

        // Upsert: check if a log already exists for this slot (same exercise + date + position)
        $existingBySlot = LiftLog::where('user_id', $user->id)
            ->where('exercise_id', $exercise->id)
            ->whereDate('logged_at', $validated['date'])
            ->where('track', $validated['track'] ?? null)
            ->where('block_index', $validated['block_index'] ?? null)
            ->where('movement_index', $validated['movement_index'] ?? null)
            ->first();

        if ($existingBySlot) {
            // Update existing log
            $existingBySlot->update([
                'comments' => $validated['note'] ?? null,
                'log_type' => $validated['log_type'],
                'device_id' => $deviceId,
                'idempotency_key' => $validated['idempotency_key'] ?? null,
            ]);

            // Replace sets: delete old, create new
            $existingBySlot->liftSets()->forceDelete();
            foreach ($validated['sets'] as $setData) {
                $mapped = $this->setFieldMapper->mapToColumns(
                    $validated['log_type'],
                    $setData,
                    $validated['weight_unit']
                );
                $existingBySlot->liftSets()->create($mapped);
            }

            // Touch updated_at so pull sync picks up the change
            $existingBySlot->touch();

            LiftLogCompleted::dispatch($existingBySlot, false);

            return $existingBySlot;
        }

        // Create the lift log
        $liftLog = LiftLog::create([
            'exercise_id' => $exercise->id,
            'user_id' => $user->id,
            'comments' => $validated['note'] ?? null,
            'logged_at' => $loggedAt,
            'log_type' => $validated['log_type'],
            'device_id' => $deviceId,
            'source' => 'sync',
            'track' => $validated['track'] ?? null,
            'block_index' => $validated['block_index'] ?? null,
            'movement_index' => $validated['movement_index'] ?? null,
            'idempotency_key' => $validated['idempotency_key'] ?? null,
        ]);

        // Create lift sets
        foreach ($validated['sets'] as $setData) {
            $mapped = $this->setFieldMapper->mapToColumns(
                $validated['log_type'],
                $setData,
                $validated['weight_unit']
            );
            $liftLog->liftSets()->create($mapped);
        }

        // Dispatch completion event for PR detection
        LiftLogCompleted::dispatch($liftLog, false);

        return $liftLog;
    }
}
