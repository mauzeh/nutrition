<?php

use App\Models\Exercise;
use App\Models\ExerciseAlias;
use App\Models\ExerciseMergeLog;
use App\Models\LiftLog;
use App\Services\ExerciseMergeService;
use App\Services\PRRecalculationService;
use App\Sync\Services\RehydrationService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Resolve exact global rows
        $target = Exercise::whereNull('user_id')
            ->whereNull('deleted_at')
            ->where('title', 'Strict Pull-Ups')
            ->where('log_type', 'bodyweight')
            ->first();

        $source1 = Exercise::whereNull('user_id')
            ->whereNull('deleted_at')
            ->where('title', 'Pull-Up')
            ->where('log_type', 'bodyweight-reps')
            ->first();

        $source2 = Exercise::whereNull('user_id')
            ->whereNull('deleted_at')
            ->where('title', 'Pull-Ups')
            ->where('log_type', 'bodyweight')
            ->first();

        // Idempotency check: if sources are already gone/soft-deleted, target exists or is already updated
        if (! $source1 && ! $source2) {
            return;
        }

        if (! $target) {
            throw new \RuntimeException('Target exercise [Strict Pull-Ups (bodyweight)] not found or ambiguous.');
        }

        $sources = array_filter([$source1, $source2]);
        $sourceCanonicals = array_values(array_map(fn ($s) => $s->canonical_name, $sources));

        $mergeMap = [
            'target' => 'strict_pull_up',
            'title' => 'Strict Pull-Ups',
            'sources' => $sourceCanonicals,
        ];

        /** @var ExerciseMergeService $mergeService */
        $mergeService = app(ExerciseMergeService::class);
        $affectedUserIds = $mergeService->mergeByMap($mergeMap);

        // Ensure target exercise log_type is canonical bodyweight
        $target->fresh()?->update(['log_type' => 'bodyweight']);

        if (empty($affectedUserIds)) {
            return;
        }

        /** @var PRRecalculationService $prService */
        $prService = app(PRRecalculationService::class);
        foreach ($affectedUserIds as $userId) {
            $prService->recalculateAllPRsForExercise($userId, $target->id);
        }

        /** @var RehydrationService $rehydrationService */
        $rehydrationService = app(RehydrationService::class);
        $rehydrationService->raiseForUsers($affectedUserIds, 'exercise-merge');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $target = Exercise::whereNull('user_id')
            ->withTrashed()
            ->where('canonical_name', 'strict_pull_up')
            ->first();

        if (! $target) {
            return;
        }

        $logs = ExerciseMergeLog::where('target_exercise_id', $target->id)->get();
        if ($logs->isEmpty()) {
            return;
        }

        $affectedUserIds = [];

        foreach ($logs as $mergeLog) {
            $snapshot = $mergeLog->snapshot ?? [];
            $sourceData = $snapshot['source'] ?? null;
            $targetOriginal = $snapshot['target_original'] ?? null;
            $aliasesCreated = $snapshot['aliases_created'] ?? [];

            $sourceId = $mergeLog->source_exercise_id;
            $source = Exercise::withTrashed()->find($sourceId);

            if ($source && $source->trashed()) {
                $source->restore();
                if ($sourceData) {
                    $source->update([
                        'canonical_name' => $sourceData['canonical_name'] ?? $source->canonical_name,
                        'title' => $sourceData['title'] ?? $source->title,
                        'log_type' => $sourceData['log_type'] ?? $source->log_type,
                        'exercise_type' => $sourceData['exercise_type'] ?? $source->exercise_type,
                    ]);
                }
            }

            // Restore lift logs ownership
            $liftLogIds = $mergeLog->lift_log_ids ?? [];
            if (! empty($liftLogIds)) {
                LiftLog::whereIn('id', $liftLogIds)->update(['exercise_id' => $sourceId]);
                $users = LiftLog::whereIn('id', $liftLogIds)->pluck('user_id')->filter()->all();
                foreach ($users as $uId) {
                    $affectedUserIds[$uId] = $uId;
                }
            }

            // Delete merge-created aliases
            if (! empty($aliasesCreated)) {
                ExerciseAlias::whereIn('id', $aliasesCreated)->forceDelete();
            }

            // Restore target original canonical name & title if captured
            if ($targetOriginal) {
                $target->update([
                    'canonical_name' => $targetOriginal['canonical_name'] ?? $target->canonical_name,
                    'title' => $targetOriginal['title'] ?? $target->title,
                ]);
            }
        }

        $affectedUserIds = array_values($affectedUserIds);

        /** @var PRRecalculationService $prService */
        $prService = app(PRRecalculationService::class);
        foreach ($affectedUserIds as $userId) {
            $prService->recalculateAllPRsForExercise($userId, $target->id);

            foreach ($logs as $mergeLog) {
                if ($mergeLog->source_exercise_id) {
                    $prService->recalculateAllPRsForExercise($userId, $mergeLog->source_exercise_id);
                }
            }
        }

        if (! empty($affectedUserIds)) {
            /** @var RehydrationService $rehydrationService */
            $rehydrationService = app(RehydrationService::class);
            $rehydrationService->raiseForUsers($affectedUserIds, 'exercise-merge-rollback');
        }
    }
};
