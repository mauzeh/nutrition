<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\ExerciseAlias;
use App\Models\ExerciseMergeLog;
use App\Models\LiftLog;
use App\Models\User;
use App\Services\ExerciseMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExerciseMergeServiceMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_by_map_repoints_logs_soft_deletes_sources_creates_aliases_and_returns_affected_users(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $target = Exercise::create([
            'user_id' => null,
            'title' => 'Strict Pull-Ups',
            'canonical_name' => 'strict_pull_up',
            'exercise_type' => 'regular',
            'log_type' => 'bodyweight',
        ]);

        $source1 = Exercise::create([
            'user_id' => null,
            'title' => 'Pull-Up',
            'canonical_name' => 'pull_ups',
            'exercise_type' => 'regular',
            'log_type' => 'bodyweight-reps',
        ]);

        $source2 = Exercise::create([
            'user_id' => null,
            'title' => 'Pull-Ups',
            'canonical_name' => 'pull_up',
            'exercise_type' => 'regular',
            'log_type' => 'bodyweight',
        ]);

        $log1 = LiftLog::create([
            'user_id' => $user1->id,
            'exercise_id' => $source1->id,
            'logged_at' => now()->subDays(5),
            'log_type' => 'bodyweight-reps',
        ]);

        $log2 = LiftLog::create([
            'user_id' => $user2->id,
            'exercise_id' => $source2->id,
            'logged_at' => now()->subDays(2),
            'log_type' => 'bodyweight',
        ]);

        /** @var ExerciseMergeService $service */
        $service = app(ExerciseMergeService::class);

        $mergeMap = [
            'target' => 'strict_pull_up',
            'title' => 'Strict Pull-Ups',
            'sources' => ['pull_ups', 'pull_up'],
        ];

        $affected = $service->mergeByMap($mergeMap);

        sort($affected);
        $expectedUsers = [$user1->id, $user2->id];
        sort($expectedUsers);
        $this->assertEquals($expectedUsers, $affected);

        $this->assertEquals($target->id, $log1->fresh()->exercise_id);
        $this->assertEquals($target->id, $log2->fresh()->exercise_id);

        $this->assertTrue($source1->fresh()->trashed());
        $this->assertTrue($source2->fresh()->trashed());

        $this->assertDatabaseHas('exercise_aliases', [
            'exercise_id' => $target->id,
            'alias_name' => 'pull_ups',
        ]);
        $this->assertDatabaseHas('exercise_aliases', [
            'exercise_id' => $target->id,
            'alias_name' => 'pull_up',
        ]);

        $this->assertDatabaseHas('exercise_merge_logs', [
            'source_exercise_id' => $source1->id,
            'target_exercise_id' => $target->id,
        ]);

        // Assert re-running is a clean no-op
        $secondRun = $service->mergeByMap($mergeMap);
        $this->assertEmpty($secondRun);
    }
}
