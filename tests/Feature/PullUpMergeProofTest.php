<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\ExerciseAlias;
use App\Models\LiftLog;
use App\Models\PersonalRecord;
use App\Models\RehydrationSignal;
use App\Models\User;
use App\Services\ExerciseMergeService;
use App\Services\PRRecalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PullUpMergeProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_pull_up_merge_combines_history_correctly_and_reverses_structurally(): void
    {
        $user = User::factory()->create();

        // Target exercise: Strict Pull-Ups
        $target = Exercise::create([
            'user_id' => null,
            'title' => 'Strict Pull-Ups',
            'canonical_name' => 'strict_pull_up',
            'exercise_type' => 'regular',
            'log_type' => 'bodyweight',
        ]);

        // Historical source: Pull-Up (3 sessions, best max reps ~60)
        $source1 = Exercise::create([
            'user_id' => null,
            'title' => 'Pull-Up',
            'canonical_name' => 'pull_ups',
            'exercise_type' => 'regular',
            'log_type' => 'bodyweight-reps',
        ]);

        // Recent source: Pull-Ups (1 session, 36 reps)
        $source2 = Exercise::create([
            'user_id' => null,
            'title' => 'Pull-Ups',
            'canonical_name' => 'pull_up',
            'exercise_type' => 'regular',
            'log_type' => 'bodyweight',
        ]);

        // Seed historical logs on source1 (older session: 60 reps)
        $historicalLog = LiftLog::create([
            'user_id' => $user->id,
            'exercise_id' => $source1->id,
            'logged_at' => now()->subMonths(3),
            'log_type' => 'bodyweight-reps',
        ]);
        $historicalLog->liftSets()->create(['weight' => 0, 'reps' => 60, 'unit' => 'lbs']);

        // Seed recent log on source2 (recent session: 36 reps)
        $recentLog = LiftLog::create([
            'user_id' => $user->id,
            'exercise_id' => $source2->id,
            'logged_at' => now()->subDays(2),
            'log_type' => 'bodyweight',
        ]);
        $recentLog->liftSets()->create(['weight' => 0, 'reps' => 36, 'unit' => 'lbs']);

        /** @var PRRecalculationService $prService */
        $prService = app(PRRecalculationService::class);

        // Compute PRs prior to merge on sources
        $prService->recalculateAllPRsForExercise($user->id, $source1->id);
        $prService->recalculateAllPRsForExercise($user->id, $source2->id);

        // Before merge: recent session IS a PR on source2
        $this->assertDatabaseHas('personal_records', [
            'user_id' => $user->id,
            'exercise_id' => $source2->id,
            'lift_log_id' => $recentLog->id,
        ]);

        // Perform merge by map + recalculate PRs directly
        $mergeMap = [
            'target' => 'strict_pull_up',
            'title' => 'Strict Pull-Ups',
            'sources' => ['pull_ups', 'pull_up'],
        ];

        /** @var ExerciseMergeService $mergeService */
        $mergeService = app(ExerciseMergeService::class);
        $affected = $mergeService->mergeByMap($mergeMap);

        $this->assertContains($user->id, $affected);

        foreach ($affected as $uId) {
            $prService->recalculateAllPRsForExercise($uId, $target->id);
        }

        // Assert target holds all logs
        $this->assertEquals(2, LiftLog::where('exercise_id', $target->id)->count());
        $this->assertEquals($target->id, $historicalLog->fresh()->exercise_id);
        $this->assertEquals($target->id, $recentLog->fresh()->exercise_id);

        // Assert PRs reflect combined history: 60-rep historical log is PR, 36-rep recent log is NOT a PR
        $pr60 = PersonalRecord::where('user_id', $user->id)
            ->where('exercise_id', $target->id)
            ->where('lift_log_id', $historicalLog->id)
            ->first();
        $this->assertNotNull($pr60);
        $this->assertEquals(60, $pr60->value);

        $pr36 = PersonalRecord::where('user_id', $user->id)
            ->where('exercise_id', $target->id)
            ->where('lift_log_id', $recentLog->id)
            ->first();
        $this->assertNull($pr36);

        // Now test the STRUCTURAL reverse (simulating migration down())
        $migration = require database_path('migrations/2026_09_01_070639_merge_pull_up_variants.php');
        $migration->down();

        // Assert structural reverse state:
        $this->assertEquals($source1->id, $historicalLog->fresh()->exercise_id);
        $this->assertEquals($source2->id, $recentLog->fresh()->exercise_id);

        $this->assertFalse($source1->fresh()->trashed());
        $this->assertFalse($source2->fresh()->trashed());

        $this->assertDatabaseMissing('exercise_aliases', [
            'exercise_id' => $target->id,
            'alias_name' => 'pull_ups',
        ]);

        // Assert a new rehydration token was raised
        $this->assertDatabaseHas('rehydration_signals', [
            'user_id' => $user->id,
            'reason' => 'exercise-merge-rollback',
        ]);

        // Assert PRs recomputed validly on restored exercises
        $this->assertDatabaseHas('personal_records', [
            'user_id' => $user->id,
            'exercise_id' => $source1->id,
            'lift_log_id' => $historicalLog->id,
            'value' => 60,
        ]);
        $this->assertDatabaseHas('personal_records', [
            'user_id' => $user->id,
            'exercise_id' => $source2->id,
            'lift_log_id' => $recentLog->id,
            'value' => 36,
        ]);
    }
}
