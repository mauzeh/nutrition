<?php

namespace Tests\Feature\Sync\EdgeCases;

use App\Models\Exercise;
use App\Models\ExerciseAlias;
use App\Models\User;
use App\Sync\Services\ExerciseResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Area 2 (Server-side): Exercise Name Resolution Edge Cases
 *
 * Validates that Logger always resolves exercises correctly and
 * always includes exerciseName in API responses.
 */
class ExerciseResolutionTest extends TestCase
{
    use RefreshDatabase;

    private ExerciseResolverService $resolver;
    private User $user;
    private string $token;
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ExerciseResolverService;
        $this->user = User::factory()->create(['email' => 'test@example.com']);
        $this->token = $this->user->createToken('test-device')->plainTextToken;
        $this->headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Device-Id' => 'device-test',
        ];
    }

    /**
     * #29: Exercise auto-created from sync includes log_type.
     */
    public function test_exercise_auto_created_from_sync_includes_log_type(): void
    {
        $exercise = $this->resolver->resolve('Brand New Exercise', $this->user, 'cardio');

        $this->assertEquals('brand_new_exercise', $exercise->canonical_name);
        $this->assertEquals('cardio', $exercise->log_type);
        $this->assertEquals('cardio', $exercise->exercise_type);
    }

    /**
     * #30: Exercise resolved by alias returns the aliased exercise, not a new one.
     */
    public function test_exercise_resolved_by_alias_returns_aliased_exercise(): void
    {
        $exercise = Exercise::create([
            'title' => 'Back Squat',
            'canonical_name' => 'back_squat',
            'exercise_type' => 'regular',
        ]);

        ExerciseAlias::create([
            'exercise_id' => $exercise->id,
            'alias_name' => 'Barbell Back Squat',
            'user_id' => null,
        ]);

        $resolved = $this->resolver->resolve('Barbell Back Squat', $this->user);

        $this->assertEquals($exercise->id, $resolved->id);
        // No duplicate created
        $this->assertEquals(1, Exercise::where('canonical_name', 'back_squat')->count());
    }

    /**
     * #31: Changes endpoint always includes exerciseName alongside exerciseId.
     */
    public function test_changes_endpoint_always_includes_exercise_name(): void
    {
        $exercise = Exercise::create([
            'title' => 'Deadlift',
            'canonical_name' => 'deadlift',
            'exercise_type' => 'regular',
            'log_type' => 'barbell',
        ]);

        $liftLog = $this->user->liftLogs()->create([
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
            'log_type' => 'barbell',
        ]);
        $liftLog->liftSets()->create(['weight' => 225, 'reps' => 5, 'unit' => 'lbs']);

        $response = $this->withHeaders($this->headers)
            ->getJson('/api/sync/changes');

        $response->assertStatus(200);
        $logs = $response->json('logs');
        $this->assertNotEmpty($logs);

        foreach ($logs as $log) {
            $this->assertArrayHasKey('exerciseId', $log);
            $this->assertArrayHasKey('exerciseName', $log);
            $this->assertNotEmpty($log['exerciseName']);
            $this->assertNotEquals($log['exerciseId'], $log['exerciseName'],
                'exerciseName should be human-readable, not the same as the snake_case exerciseId');
        }
    }

    /**
     * #32: Restore endpoint always includes exerciseName alongside exerciseId.
     */
    public function test_restore_endpoint_always_includes_exercise_name(): void
    {
        $exercise = Exercise::create([
            'title' => 'Overhead Press',
            'canonical_name' => 'overhead_press',
            'exercise_type' => 'regular',
            'log_type' => 'barbell',
        ]);

        $liftLog = $this->user->liftLogs()->create([
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
            'log_type' => 'barbell',
        ]);
        $liftLog->liftSets()->create(['weight' => 135, 'reps' => 5, 'unit' => 'lbs']);

        $response = $this->withHeaders($this->headers)
            ->getJson('/api/sync/restore');

        $response->assertStatus(200);
        $logs = $response->json('logs');
        $this->assertNotEmpty($logs);

        foreach ($logs as $log) {
            $this->assertArrayHasKey('exerciseId', $log);
            $this->assertArrayHasKey('exerciseName', $log);
            $this->assertNotEmpty($log['exerciseName']);
        }
    }

    /**
     * #33: Exercise with updated title on Logger is reflected in changes response.
     */
    public function test_exercise_title_update_reflected_in_changes(): void
    {
        $exercise = Exercise::create([
            'title' => 'Old Title',
            'canonical_name' => 'some_exercise',
            'exercise_type' => 'regular',
            'log_type' => 'barbell',
        ]);

        $liftLog = $this->user->liftLogs()->create([
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
            'log_type' => 'barbell',
        ]);
        $liftLog->liftSets()->create(['weight' => 100, 'reps' => 5, 'unit' => 'lbs']);

        // Update the title
        $exercise->update(['title' => 'New Better Title']);

        $response = $this->withHeaders($this->headers)
            ->getJson('/api/sync/changes');

        $response->assertStatus(200);
        $logs = $response->json('logs');
        $this->assertNotEmpty($logs);
        $this->assertEquals('New Better Title', $logs[0]['exerciseName']);
    }

    /**
     * #33a: Exercise auto-created from Athlete push returns exact same canonical_name on restore.
     */
    public function test_athlete_push_canonical_name_round_trips_exactly(): void
    {
        // Push a log with a specific canonical_name
        $response = $this->withHeaders($this->headers)
            ->postJson('/api/sync/logs', [
                'exercise_name' => 'Copenhagen Plank',
                'canonical_name' => 'copenhagen_plank',
                'date' => '2026-06-17',
                'log_type' => 'static-hold',
                'weight_unit' => 'lbs',
                'sets' => [['duration' => 30]],
            ]);

        $response->assertStatus(200);

        // Restore and verify canonical_name comes back identical
        $restoreResponse = $this->withHeaders($this->headers)
            ->getJson('/api/sync/restore');

        $restoreResponse->assertStatus(200);
        $logs = $restoreResponse->json('logs');
        $this->assertNotEmpty($logs);

        $copenhagenLog = collect($logs)->firstWhere('exerciseId', 'copenhagen_plank');
        $this->assertNotNull($copenhagenLog, 'Copenhagen Plank log should be in restore response');
        $this->assertEquals('copenhagen_plank', $copenhagenLog['exerciseId']);
    }

    /**
     * #33b: Exercise auto-created from Athlete push stores the exercise_name as title.
     */
    public function test_athlete_push_stores_exercise_name_as_title(): void
    {
        $this->withHeaders($this->headers)
            ->postJson('/api/sync/logs', [
                'exercise_name' => 'Zombie Squat',
                'canonical_name' => 'zombie_squat',
                'date' => '2026-06-17',
                'log_type' => 'barbell',
                'weight_unit' => 'lbs',
                'sets' => [['weight' => 95, 'reps' => 8]],
            ]);

        $exercise = Exercise::where('canonical_name', 'zombie_squat')->first();
        $this->assertNotNull($exercise);
        $this->assertEquals('Zombie Squat', $exercise->title);

        // Verify it comes back with the correct name on restore
        $response = $this->withHeaders($this->headers)
            ->getJson('/api/sync/restore');

        $log = collect($response->json('logs'))->firstWhere('exerciseId', 'zombie_squat');
        $this->assertEquals('Zombie Squat', $log['exerciseName']);
    }
}
