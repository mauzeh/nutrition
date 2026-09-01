<?php

namespace App\Services;

use App\Models\Exercise;
use App\Models\ExerciseMergeLog;
use App\Models\LiftLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExerciseMergeService
{
    protected ExerciseAliasService $aliasService;

    public function __construct(ExerciseAliasService $aliasService)
    {
        $this->aliasService = $aliasService;
    }

    /**
     * Determine if an exercise can be merged by an admin
     */
    public function canBeMerged(Exercise $sourceExercise): bool
    {
        // Only user exercises can be merged (not global exercises)
        if ($sourceExercise->isGlobal()) {
            return false;
        }

        // Must have at least one potential target
        return $this->getPotentialTargets($sourceExercise)->isNotEmpty();
    }

    /**
     * Get potential global target exercises for merging
     */
    public function getPotentialTargets(Exercise $sourceExercise): Collection
    {
        return Exercise::onlyGlobal()
            ->where('id', '!=', $sourceExercise->id)
            ->get()
            ->filter(function ($target) use ($sourceExercise) {
                return $sourceExercise->isCompatibleForMerge($target);
            })
            ->sortBy('title')
            ->values();
    }

    /**
     * Validate merge compatibility between source and target exercises
     */
    public function validateMergeCompatibility(Exercise $source, Exercise $target): array
    {
        $errors = [];
        $warnings = [];

        // Target must be global
        if (! $target->isGlobal()) {
            $errors[] = 'Target exercise must be a global exercise.';
        }

        // Cannot merge into self
        if ($source->id === $target->id) {
            $errors[] = 'Cannot merge exercise into itself.';
        }

        // Check exercise type compatibility using the model's method
        if (! $source->isCompatibleForMerge($target)) {
            $errors[] = 'Exercises have incompatible types.';
        }

        // Check if source exercise owner has global visibility disabled
        if ($source->user && ! $source->user->shouldShowGlobalExercises()) {
            $warnings[] = 'The owner of this exercise has global exercise visibility disabled. They will lose access to their exercise data after the merge.';
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'can_merge' => empty($errors),
        ];
    }

    /**
     * Perform the exercise merge operation
     */
    public function mergeExercises(Exercise $source, Exercise $target, User $admin, bool $createAlias = true): bool
    {
        // Validate compatibility first
        $validation = $this->validateMergeCompatibility($source, $target);
        if (! $validation['can_merge']) {
            throw new \InvalidArgumentException('Exercises are not compatible for merging: '.implode(', ', $validation['errors']));
        }

        // Collect lift log IDs before transfer
        $liftLogIds = LiftLog::where('exercise_id', $source->id)->pluck('id')->toArray();

        try {
            DB::beginTransaction();

            // Transfer lift logs
            $this->transferLiftLogs($source, $target);

            // Handle exercise intelligence
            $this->handleExerciseIntelligence($source, $target);

            // Create alias for the source exercise owner if requested
            $aliasCreated = $this->createAliasForOwner($source, $target, $createAlias);

            // Create global canonical name alias if not redundant
            $this->createCanonicalNameAlias($source, $target);

            // Delete the source exercise
            $source->delete();

            // Create database log entry
            ExerciseMergeLog::create([
                'source_exercise_id' => $source->id,
                'source_exercise_title' => $source->title,
                'target_exercise_id' => $target->id,
                'target_exercise_title' => $target->title,
                'admin_user_id' => $admin->id,
                'admin_email' => $admin->email,
                'lift_log_ids' => $liftLogIds,
                'lift_log_count' => count($liftLogIds),
                'alias_created' => $aliasCreated,
            ]);

            // Also log to Laravel log for immediate visibility
            Log::info('Exercise merge completed', [
                'source_exercise_id' => $source->id,
                'source_exercise_title' => $source->title,
                'target_exercise_id' => $target->id,
                'target_exercise_title' => $target->title,
                'admin_user_id' => $admin->id,
                'admin_email' => $admin->email,
                'lift_log_count' => count($liftLogIds),
                'alias_created' => $aliasCreated,
            ]);

            DB::commit();

            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Exercise merge failed', [
                'source_exercise_id' => $source->id,
                'target_exercise_id' => $target->id,
                'admin_user_id' => $admin->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Transfer all lift logs from source to target exercise
     */
    private function transferLiftLogs(Exercise $source, Exercise $target): void
    {
        $liftLogs = LiftLog::where('exercise_id', $source->id)->get();

        foreach ($liftLogs as $liftLog) {
            // Update exercise_id to target without modifying comments
            $liftLog->update(['exercise_id' => $target->id]);
        }
    }

    /**
     * Handle exercise intelligence transfer
     */
    private function handleExerciseIntelligence(Exercise $source, Exercise $target): void
    {
        $sourceIntelligence = $source->intelligence;
        $targetIntelligence = $target->intelligence;

        // If source has intelligence and target doesn't, transfer it
        if ($sourceIntelligence && ! $targetIntelligence) {
            $sourceIntelligence->update(['exercise_id' => $target->id]);
        }
        // If both have intelligence, keep target's intelligence (source will be deleted with exercise)
        // No action needed as source intelligence will be cascade deleted
    }

    /**
     * Create alias for the source exercise owner if requested
     *
     * @return bool Whether an alias was created
     */
    private function createAliasForOwner(Exercise $source, Exercise $target, bool $createAlias): bool
    {
        // Don't create alias if not requested
        if (! $createAlias) {
            return false;
        }

        // Don't create alias if source has no owner (global exercise)
        if (! $source->user) {
            return false;
        }

        try {
            $this->aliasService->createAlias(
                $source->user,
                $target,
                $source->title
            );

            Log::info('Exercise alias created during merge', [
                'user_id' => $source->user->id,
                'user_email' => $source->user->email,
                'exercise_id' => $target->id,
                'exercise_title' => $target->title,
                'alias_name' => $source->title,
                'created_via' => 'merge_operation',
            ]);

            return true;
        } catch (QueryException $e) {
            // Handle duplicate alias errors gracefully
            if ($e->getCode() === '23000') {
                Log::warning('Duplicate alias during merge - alias already exists', [
                    'user_id' => $source->user->id,
                    'exercise_id' => $target->id,
                    'alias_name' => $source->title,
                ]);

                // Continue with merge - alias already exists
                return false;
            }

            // For other database errors, log and continue
            Log::error('Failed to create alias during merge', [
                'user_id' => $source->user->id,
                'exercise_id' => $target->id,
                'alias_name' => $source->title,
                'error' => $e->getMessage(),
            ]);

            // Don't fail the merge due to alias creation failure
            return false;
        } catch (\Exception $e) {
            // Catch any other exceptions and log them
            Log::error('Unexpected error creating alias during merge', [
                'user_id' => $source->user->id,
                'exercise_id' => $target->id,
                'alias_name' => $source->title,
                'error' => $e->getMessage(),
            ]);

            // Don't fail the merge due to alias creation failure
            return false;
        }
    }

    /**
     * Create a global alias for the target exercise using the source exercise's canonical name if not redundant.
     */
    private function createCanonicalNameAlias(Exercise $source, Exercise $target): void
    {
        $canonical = $source->canonical_name;
        $titleSnake = \Illuminate\Support\Str::snake($source->title);
        $titleSlug = \Illuminate\Support\Str::slug($source->title, '_');

        // Skip if canonical_name is identical to snake_case or slug of title
        if ($canonical === $titleSnake || $canonical === $titleSlug) {
            return;
        }

        // Check if alias already exists for the target exercise
        $exists = \App\Models\ExerciseAlias::query()
            ->where('exercise_id', $target->id)
            ->where('alias_name', $canonical)
            ->exists();

        if ($exists) {
            return;
        }

        try {
            \App\Models\ExerciseAlias::create([
                'user_id' => null, // Global alias
                'exercise_id' => $target->id,
                'alias_name' => $canonical,
            ]);

            Log::info('Global canonical name alias created during merge', [
                'target_exercise_id' => $target->id,
                'alias_name' => $canonical,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Check if it's a duplicate entry error
            if ($e->getCode() === '23000') {
                Log::warning('Duplicate canonical alias during merge - alias already exists', [
                    'exercise_id' => $target->id,
                    'alias_name' => $canonical,
                ]);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Get merge statistics for display purposes
     */
    public function getMergeStatistics(Exercise $exercise): array
    {
        return [
            'lift_logs_count' => $exercise->liftLogs()->count(),
            'has_intelligence' => $exercise->hasIntelligence(),
            'users_count' => $exercise->liftLogs()->distinct('user_id')->count('user_id'),
        ];
    }

    /**
     * Perform a map-driven exercise merge across global exercises.
     *
     * @param array{target: string, title: string, sources: array<int, string>} $merge
     * @return array<int, int> Affected user IDs
     */
    public function mergeByMap(array $merge): array
    {
        $targetIdentifier = $merge['target'];
        $targetTitle = $merge['title'];
        $sourceIdentifiers = $merge['sources'];

        // Resolve target global exercise
        $target = $this->resolveGlobalExercise($targetIdentifier, $targetTitle);
        if (! $target) {
            throw new \RuntimeException("Target exercise [{$targetIdentifier} / {$targetTitle}] not found.");
        }

        // Resolve source global exercises
        $sources = [];
        foreach ($sourceIdentifiers as $sourceId) {
            $source = $this->resolveGlobalExercise($sourceId, $sourceId);
            if ($source) {
                if ($source->id === $target->id) {
                    continue;
                }
                $sources[$source->id] = $source;
            }
        }

        if (empty($sources)) {
            // Idempotent: all sources already merged / deleted / absent
            return [];
        }

        $affectedUserIds = [];

        DB::transaction(function () use ($merge, $target, $sources, &$affectedUserIds) {
            $targetOriginalCanonical = $target->canonical_name;
            $targetOriginalTitle = $target->title;

            foreach ($sources as $source) {
                // Collect lift log IDs and affected user IDs
                $liftLogs = LiftLog::where('exercise_id', $source->id)->get();
                $liftLogIds = $liftLogs->pluck('id')->all();
                foreach ($liftLogs as $log) {
                    if ($log->user_id) {
                        $affectedUserIds[$log->user_id] = $log->user_id;
                    }
                }

                // Repoint lift logs
                LiftLog::where('exercise_id', $source->id)->update(['exercise_id' => $target->id]);

                // Handle exercise intelligence
                $this->handleExerciseIntelligence($source, $target);

                // Create global alias for source canonical_name and title on target
                $aliasesCreated = [];
                $aliasesToCreate = array_filter(array_unique([$source->canonical_name, $source->title]));
                foreach ($aliasesToCreate as $aliasName) {
                    $exists = \App\Models\ExerciseAlias::query()
                        ->where('exercise_id', $target->id)
                        ->where('alias_name', $aliasName)
                        ->whereNull('user_id')
                        ->exists();

                    if (! $exists) {
                        $aliasModel = \App\Models\ExerciseAlias::create([
                            'user_id' => null,
                            'exercise_id' => $target->id,
                            'alias_name' => $aliasName,
                        ]);
                        $aliasesCreated[] = $aliasModel->id;
                    }
                }

                // Create audit log
                ExerciseMergeLog::create([
                    'source_exercise_id' => $source->id,
                    'source_exercise_title' => $source->title,
                    'target_exercise_id' => $target->id,
                    'target_exercise_title' => $target->title,
                    'admin_user_id' => null,
                    'admin_email' => null,
                    'lift_log_ids' => $liftLogIds,
                    'lift_log_count' => count($liftLogIds),
                    'alias_created' => ! empty($aliasesCreated),
                    'snapshot' => [
                        'source' => [
                            'id' => $source->id,
                            'canonical_name' => $source->canonical_name,
                            'title' => $source->title,
                            'log_type' => $source->log_type,
                            'exercise_type' => $source->exercise_type,
                        ],
                        'target_original' => [
                            'id' => $target->id,
                            'canonical_name' => $targetOriginalCanonical,
                            'title' => $targetOriginalTitle,
                        ],
                        'aliases_created' => $aliasesCreated,
                    ],
                ]);

                // Soft-delete source exercise
                $source->delete();
            }

            // Set target canonical name and title per map (naming authority)
            $target->update([
                'canonical_name' => $merge['target'],
                'title' => $merge['title'],
            ]);
        });

        return array_values($affectedUserIds);
    }

    /**
     * Resolve a global exercise by canonical_name, then by title (case-insensitive).
     */
    private function resolveGlobalExercise(string $identifier, string $fallbackTitle): ?Exercise
    {
        $query = Exercise::whereNull('user_id')->whereNull('deleted_at');

        $matches = (clone $query)->where('canonical_name', $identifier)->get();

        if ($matches->count() > 1) {
            throw new \RuntimeException("Ambiguous resolution for global exercise canonical_name [{$identifier}]: multiple rows found.");
        }

        if ($matches->count() === 1) {
            return $matches->first();
        }

        $titleMatches = (clone $query)->whereRaw('LOWER(title) = ?', [mb_strtolower($fallbackTitle)])->get();

        if ($titleMatches->count() > 1) {
            throw new \RuntimeException("Ambiguous resolution for global exercise title [{$fallbackTitle}]: multiple rows found.");
        }

        return $titleMatches->first();
    }
}
