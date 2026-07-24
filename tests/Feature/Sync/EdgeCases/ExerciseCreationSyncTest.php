<?php

namespace Tests\Feature\Sync\EdgeCases;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercise Creation via Sync API — edge cases and erratic user behavior.
 *
 * Validates that Logger correctly handles name-derived canonical IDs
 * sent by Athlete, including duplicate submissions, cross-user parity,
 * and interaction with global exercises.
 */
class ExerciseCreationSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;
    private User $userB;
    private array $headersA;
    private array $headersB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create(['email' => 'usera@test.com', 'name' => 'User A']);
        $this->userB = User::factory()->create(['email' => 'userb@test.com', 'name' => 'User B']);

        $tokenA = $this->userA->createToken('device-a')->plainTextToken;
        $tokenB = $this->userB->createToken('device-b')->plainTextToken;

        $this->headersA = ['Authorization' => 'Bearer ' . $tokenA, 'X-Device-Id' => 'device-a'];
        $this->headersB = ['Authorization' => 'Bearer ' . $tokenB, 'X-Device-Id' => 'device-b'];
    }

    /**
     * Clean name-derived canonical is stored correctly.
     */
    public function test_creates_exercise_with_name_derived_canonical(): void
    {
        $response = $this->withHeaders($this->headersA)->postJson('/api/sync/exercises', [
            'title' => 'Jefferson Squat',
            'canonical_name' => 'jefferson_squat',
            'exercise_type' => 'regular',
            'log_type' => 'barbell',
            'show_in_feed' => true,
        ]);

        $response->assertStatus(201)->assertJson([
            'status' => 'ok',
            'canonical_name' => 'jefferson_squat',
        ]);

        $this->assertDatabaseHas('exercises', [
            'canonical_name' => 'jefferson_squat',
            'title' => 'Jefferson Squat',
            'user_id' => $this->userA->id,
            'exercise_type' => 'regular',
            'log_type' => 'barbell',
        ]);
    }

    /**
     * Same user sends same canonical twice (idempotent upsert).
     * Simulates: athlete creates exercise, sync fires, network retry fires again.
     */
    public function test_same_user_same_canonical_is_idempotent(): void
    {
        $payload = [
            'title' => 'Leg Press',
            'canonical_name' => 'leg_press',
            'exercise_type' => 'regular',
            'log_type' => 'machine',
            'show_in_feed' => true,
        ];

        $first = $this->withHeaders($this->headersA)->postJson('/api/sync/exercises', $payload);
        $first->assertStatus(201);

        $second = $this->withHeaders($this->headersA)->postJson('/api/sync/exercises', $payload);
        $second->assertStatus(200); // upsert, not duplicate

        // Only one exercise exists for this user
        $count = Exercise::where('user_id', $this->userA->id)
            ->where('canonical_name', 'leg_press')
            ->count();
        $this->assertEquals(1, $count);
    }

    /**
     * Same user sends incrementing canonicals with slightly different titles.
     * Simulates: user creates "Leg Press" and "Leg Press " (trailing space trimmed to same title).
     * In practice, Athlete prevents same-name duplicates via search — but if canonicals differ,
     * Logger should handle it gracefully via the canonical_name upsert path.
     */
    public function test_same_user_incrementing_canonicals_creates_distinct_exercises(): void
    {
        $this->withHeaders($this->headersA)->postJson('/api/sync/exercises', [
            'title' => 'Leg Press',
            'canonical_name' => 'leg_press',
            'exercise_type' => 'regular',
            'log_type' => 'machine',
            'show_in_feed' => true,
        ])->assertStatus(201);

        // Second exercise has a different title (user renamed it or it's truly distinct)
        $this->withHeaders($this->headersA)->postJson('/api/sync/exercises', [
            'title' => 'Leg Press (Seated)',
            'canonical_name' => 'leg_press_1',
            'exercise_type' => 'regular',
            'log_type' => 'machine',
            'show_in_feed' => true,
        ])->assertStatus(201);

        $exercises = Exercise::where('user_id', $this->userA->id)
            ->whereIn('canonical_name', ['leg_press', 'leg_press_1'])
            ->get();

        $this->assertCount(2, $exercises);
    }

    /**
     * Two different users send the same canonical and title independently.
     * Simulates: User A and User B both create "Leg Press" → both get leg_press.
     * The UNIQUE(title, user_id) constraint allows this because user_id differs.
     */
    public function test_different_users_same_canonical_no_collision(): void
    {
        $payload = [
            'title' => 'Leg Press',
            'canonical_name' => 'leg_press',
            'exercise_type' => 'regular',
            'log_type' => 'machine',
            'show_in_feed' => true,
        ];

        $responseA = $this->actingAs($this->userA)
            ->withHeaders(['X-Device-Id' => 'device-a'])
            ->postJson('/api/sync/exercises', $payload);
        $responseA->assertSuccessful();

        $responseB = $this->actingAs($this->userB)
            ->withHeaders(['X-Device-Id' => 'device-b'])
            ->postJson('/api/sync/exercises', $payload);
        $responseB->assertSuccessful();

        // Both users have their own leg_press
        $this->assertDatabaseHas('exercises', [
            'canonical_name' => 'leg_press',
            'user_id' => $this->userA->id,
        ]);
        $this->assertDatabaseHas('exercises', [
            'canonical_name' => 'leg_press',
            'user_id' => $this->userB->id,
        ]);
    }

    /**
     * User-scoped exercise canonical collides with an existing global exercise.
     * The user-scoped one should still be created — they coexist.
     */
    public function test_user_canonical_coexists_with_global_exercise(): void
    {
        // Create a global exercise
        Exercise::create([
            'title' => 'Bench Press',
            'canonical_name' => 'bench_press',
            'exercise_type' => 'regular',
            'log_type' => 'barbell',
            'user_id' => null,
        ]);

        // User sends same canonical (derived from same name)
        $response = $this->withHeaders($this->headersA)->postJson('/api/sync/exercises', [
            'title' => 'Bench Press',
            'canonical_name' => 'bench_press_1',
            'exercise_type' => 'regular',
            'log_type' => 'barbell',
            'show_in_feed' => true,
        ]);

        $response->assertStatus(201);

        // Both exist: global bench_press + user bench_press_1
        $this->assertDatabaseHas('exercises', [
            'canonical_name' => 'bench_press',
            'user_id' => null,
        ]);
        $this->assertDatabaseHas('exercises', [
            'canonical_name' => 'bench_press_1',
            'user_id' => $this->userA->id,
        ]);
    }

    /**
     * Exercise appears in restore response scoped to the creating user.
     */
    public function test_created_exercise_appears_in_restore_for_owner_only(): void
    {
        // User A creates an exercise
        $this->actingAs($this->userA)
            ->withHeaders(['X-Device-Id' => 'device-a'])
            ->postJson('/api/sync/exercises', [
                'title' => 'Zercher Deadlift',
                'canonical_name' => 'zercher_deadlift',
                'exercise_type' => 'regular',
                'log_type' => 'barbell',
                'show_in_feed' => true,
            ])->assertStatus(201);

        // User A's restore includes the exercise
        $restoreA = $this->actingAs($this->userA)
            ->withHeaders(['X-Device-Id' => 'device-a'])
            ->getJson('/api/sync/restore?athlete=' . urlencode($this->userA->name) . '&device_id=device-a');
        $restoreA->assertStatus(200);
        $userExercisesA = $restoreA->json('userExercises');
        $this->assertCount(1, $userExercisesA);
        $this->assertEquals('zercher_deadlift', $userExercisesA[0]['id']);

        // User B's restore does NOT include User A's exercise
        $restoreB = $this->actingAs($this->userB)
            ->withHeaders(['X-Device-Id' => 'device-b'])
            ->getJson('/api/sync/restore?athlete=' . urlencode($this->userB->name) . '&device_id=device-b');
        $restoreB->assertStatus(200);
        $userExercisesB = $restoreB->json('userExercises');
        $this->assertEmpty($userExercisesB);
    }

    /**
     * Soft-deleted exercise is restored when same canonical is sent again.
     * The controller uses withTrashed() lookup to find and restore it.
     *
     * Note: This test documents that the sync controller SHOULD handle this case.
     * If it fails with a unique constraint violation, the controller needs
     * to query withTrashed() when looking up by canonical_name.
     */
    public function test_soft_deleted_exercise_is_restored_on_resync(): void
    {
        // Create via the API first (so it goes through the normal path)
        $this->withHeaders($this->headersA)->postJson('/api/sync/exercises', [
            'title' => 'Sissy Squat',
            'canonical_name' => 'sissy_squat',
            'exercise_type' => 'regular',
            'log_type' => 'bodyweight-reps',
            'show_in_feed' => true,
        ])->assertStatus(201);

        // Soft-delete it directly
        $exercise = Exercise::where('user_id', $this->userA->id)
            ->where('canonical_name', 'sissy_squat')
            ->first();
        $exercise->delete();

        $this->assertSoftDeleted('exercises', ['canonical_name' => 'sissy_squat', 'user_id' => $this->userA->id]);

        // Athlete re-syncs — controller should find the trashed record and restore it
        $response = $this->withHeaders($this->headersA)->postJson('/api/sync/exercises', [
            'title' => 'Sissy Squat',
            'canonical_name' => 'sissy_squat',
            'exercise_type' => 'regular',
            'log_type' => 'bodyweight-reps',
            'show_in_feed' => true,
        ]);

        $response->assertStatus(200)->assertJson(['canonical_name' => 'sissy_squat']);

        // Exercise is no longer soft-deleted
        $this->assertDatabaseHas('exercises', [
            'canonical_name' => 'sissy_squat',
            'user_id' => $this->userA->id,
            'deleted_at' => null,
        ]);
    }
}
