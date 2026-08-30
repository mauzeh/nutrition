<?php

namespace Tests\Feature\Sync\EdgeCases;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Area 6: Full Round-Trip Integration Tests
 *
 * Validates the push → server storage → pull/restore contract.
 * Each test pushes data via the sync API, then verifies the response
 * shape on /changes or /restore matches what the Athlete app expects.
 */
class RoundTripTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email' => 'roundtrip@test.com']);
        $this->token = $this->user->createToken('test-device')->plainTextToken;
        $this->headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Device-Id' => 'device-roundtrip',
        ];
    }

    /**
     * #64: Positioned log retains track, blockIndex, movementIndex through restore.
     */
    public function test_positioned_log_retains_position_through_restore(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/sync/logs', [
            'exercise_name' => 'Back Squat',
            'canonical_name' => 'back_squat',
            'date' => '2026-06-17',
            'log_type' => 'barbell',
            'weight_unit' => 'lbs',
            'track' => 'peak',
            'block_index' => 2,
            'movement_index' => 3,
            'sets' => [['weight' => 225, 'reps' => 5]],
        ])->assertStatus(200);

        $response = $this->withHeaders($this->headers)->getJson('/api/sync/restore');
        $response->assertStatus(200);

        $log = collect($response->json('logs'))->firstWhere('exerciseId', 'back_squat');
        $this->assertNotNull($log);
        $this->assertEquals('peak', $log['track']);
        $this->assertEquals(2, $log['blockIndex']);
        $this->assertEquals(3, $log['movementIndex']);
    }

    /**
     * #65: Logger web log has null position fields in changes response.
     */
    public function test_logger_web_log_has_null_position_in_changes(): void
    {
        // Create a log directly (simulating Logger web UI — no track/block/movement)
        $exercise = Exercise::create([
            'title' => 'Bench Press',
            'canonical_name' => 'bench_press',
            'exercise_type' => 'regular',
            'log_type' => 'barbell',
        ]);

        $liftLog = LiftLog::create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
            'log_type' => 'barbell',
            'track' => null,
            'block_index' => null,
            'movement_index' => null,
        ]);
        $liftLog->liftSets()->create(['weight' => 135, 'reps' => 5, 'unit' => 'lbs']);

        $response = $this->withHeaders($this->headers)->getJson('/api/sync/changes');
        $response->assertStatus(200);

        $logs = $response->json('logs');
        $this->assertNotEmpty($logs);

        $log = $logs[0];
        $this->assertArrayNotHasKey('track', $log);
        $this->assertArrayNotHasKey('blockIndex', $log);
        $this->assertArrayNotHasKey('movementIndex', $log);
    }

    /**
     * #66: Auto-created exercise has correct canonical_name and title in restore.
     */
    public function test_auto_created_exercise_correct_in_restore(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/sync/logs', [
            'exercise_name' => 'Glute Bridge Single Leg',
            'canonical_name' => 'glute_bridge_single_leg',
            'date' => '2026-06-17',
            'log_type' => 'bodyweight',
            'weight_unit' => 'lbs',
            'sets' => [['reps' => 12]],
        ])->assertStatus(200);

        $response = $this->withHeaders($this->headers)->getJson('/api/sync/restore');
        $log = collect($response->json('logs'))->firstWhere('exerciseId', 'glute_bridge_single_leg');

        $this->assertNotNull($log);
        $this->assertEquals('glute_bridge_single_leg', $log['exerciseId']);
        $this->assertEquals('Glute Bridge Single Leg', $log['exerciseName']);
    }

    /**
     * #67: Upsert same slot does not create duplicate log.
     */
    public function test_upsert_same_slot_no_duplicate(): void
    {
        $payload = [
            'exercise_name' => 'Overhead Press',
            'canonical_name' => 'overhead_press',
            'date' => '2026-06-17',
            'log_type' => 'barbell',
            'weight_unit' => 'lbs',
            'track' => 'peak',
            'block_index' => 0,
            'movement_index' => 1,
            'sets' => [['weight' => 95, 'reps' => 8]],
        ];

        // First push
        $this->withHeaders($this->headers)->postJson('/api/sync/logs', $payload)->assertStatus(200);

        // Second push — same slot, different sets
        $payload['sets'] = [['weight' => 105, 'reps' => 6], ['weight' => 115, 'reps' => 4]];
        $this->withHeaders($this->headers)->postJson('/api/sync/logs', $payload)->assertStatus(200);

        // Restore should show exactly 1 log with the latest sets
        $response = $this->withHeaders($this->headers)->getJson('/api/sync/restore');
        $logs = collect($response->json('logs'))->where('exerciseId', 'overhead_press');

        $this->assertCount(1, $logs);
        $this->assertCount(2, $logs->first()['sets']);
        $this->assertEquals(105, $logs->first()['sets'][0]['weight']);
    }

    /**
     * #68: Deleted log appears in changes.deleted_ids.
     */
    public function test_deleted_log_in_changes_deleted_ids(): void
    {
        $response = $this->withHeaders($this->headers)->postJson('/api/sync/logs', [
            'exercise_name' => 'Pull Up',
            'canonical_name' => 'pull_up',
            'date' => '2026-06-17',
            'log_type' => 'bodyweight',
            'weight_unit' => 'lbs',
            'sets' => [['reps' => 10]],
        ]);
        $logId = $response->json('log_id');

        // Delete it
        $this->withHeaders($this->headers)->deleteJson("/api/sync/logs/{$logId}")->assertStatus(200);

        // Changes should include deleted_ids
        $changesResponse = $this->withHeaders($this->headers)->getJson('/api/sync/changes');
        $changesResponse->assertStatus(200);

        $deletedIds = $changesResponse->json('deleted_ids');
        $this->assertContains($logId, $deletedIds);
    }

    /**
     * #69: Deleted log does NOT appear in restore.logs.
     */
    public function test_deleted_log_not_in_restore(): void
    {
        $response = $this->withHeaders($this->headers)->postJson('/api/sync/logs', [
            'exercise_name' => 'Dip',
            'canonical_name' => 'dip',
            'date' => '2026-06-17',
            'log_type' => 'bodyweight',
            'weight_unit' => 'lbs',
            'sets' => [['reps' => 15]],
        ]);
        $logId = $response->json('log_id');

        // Delete it
        $this->withHeaders($this->headers)->deleteJson("/api/sync/logs/{$logId}")->assertStatus(200);

        // Restore should NOT include it
        $restoreResponse = $this->withHeaders($this->headers)->getJson('/api/sync/restore');
        $logs = collect($restoreResponse->json('logs'));

        $this->assertNull($logs->firstWhere('exerciseId', 'dip'));
    }

    /**
     * #70: Exercise with log_type from Athlete is used in changes/restore logType field.
     */
    public function test_log_type_from_athlete_used_in_responses(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/sync/logs', [
            'exercise_name' => 'Kettlebell Swing',
            'canonical_name' => 'kettlebell_swing',
            'date' => '2026-06-17',
            'log_type' => 'kettlebell',
            'weight_unit' => 'lbs',
            'sets' => [['weight' => 24, 'reps' => 20]],
        ])->assertStatus(200);

        $response = $this->withHeaders($this->headers)->getJson('/api/sync/restore');
        $log = collect($response->json('logs'))->firstWhere('exerciseId', 'kettlebell_swing');

        $this->assertEquals('kettlebell', $log['logType']);
    }

    /**
     * #71: Log pushed with cardio-calories type returns correct set fields on restore.
     */
    public function test_cardio_calories_set_fields_round_trip(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/sync/logs', [
            'exercise_name' => 'Assault Bike',
            'canonical_name' => 'assault_bike',
            'date' => '2026-06-17',
            'log_type' => 'cardio-calories',
            'weight_unit' => 'lbs',
            'sets' => [['calories' => 150]],
        ])->assertStatus(200);

        $response = $this->withHeaders($this->headers)->getJson('/api/sync/restore');
        $log = collect($response->json('logs'))->firstWhere('exerciseId', 'assault_bike');

        $this->assertNotNull($log);
        $this->assertEquals('cardio-calories', $log['logType']);
        $this->assertArrayHasKey('calories', $log['sets'][0]);
        $this->assertEquals(150, $log['sets'][0]['calories']);
    }

    /**
     * #72: Log pushed with static-hold type returns duration field on restore.
     */
    public function test_static_hold_duration_round_trip(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/sync/logs', [
            'exercise_name' => 'Plank',
            'canonical_name' => 'plank',
            'date' => '2026-06-17',
            'log_type' => 'static-hold',
            'weight_unit' => 'lbs',
            'sets' => [['duration' => 60]],
        ])->assertStatus(200);

        $response = $this->withHeaders($this->headers)->getJson('/api/sync/restore');
        $log = collect($response->json('logs'))->firstWhere('exerciseId', 'plank');

        $this->assertNotNull($log);
        $this->assertEquals('static-hold', $log['logType']);
        $this->assertArrayHasKey('duration', $log['sets'][0]);
        $this->assertEquals(60, $log['sets'][0]['duration']);
    }

    /**
     * A log whose sets are all soft-deleted carries no unit and is omitted
     * from the restore payload entirely (no fabricated weightUnit fallback).
     */
    public function test_log_with_no_live_sets_is_skipped_in_restore(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/sync/logs', [
            'exercise_name' => 'Front Squat',
            'canonical_name' => 'front_squat',
            'date' => '2026-06-17',
            'log_type' => 'barbell',
            'weight_unit' => 'kg',
            'sets' => [['weight' => 100, 'reps' => 5]],
        ])->assertStatus(200);

        $liftLog = LiftLog::where('user_id', $this->user->id)->firstOrFail();
        $liftLog->liftSets()->delete();

        $response = $this->withHeaders($this->headers)->getJson('/api/sync/restore');
        $response->assertStatus(200);

        $logs = collect($response->json('logs'));
        $this->assertNull($logs->firstWhere('exerciseId', 'front_squat'));
    }

    /**
     * A log whose sets are all soft-deleted is omitted from the changes payload.
     */
    public function test_log_with_no_live_sets_is_skipped_in_changes(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/sync/logs', [
            'exercise_name' => 'Back Squat',
            'canonical_name' => 'back_squat',
            'date' => '2026-06-17',
            'log_type' => 'barbell',
            'weight_unit' => 'kg',
            'sets' => [['weight' => 120, 'reps' => 3]],
        ])->assertStatus(200);

        $liftLog = LiftLog::where('user_id', $this->user->id)->firstOrFail();
        $liftLog->liftSets()->delete();

        $response = $this->withHeaders($this->headers)->getJson('/api/sync/changes');
        $response->assertStatus(200);

        $logs = collect($response->json('logs'));
        $this->assertNull($logs->firstWhere('exerciseId', 'back_squat'));
    }

    /**
     * A normal log with live sets still reports the stored per-log weightUnit
     * (no fallback), confirming the skip guard didn't drop valid logs.
     */
    public function test_log_with_live_sets_reports_stored_weight_unit(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/sync/logs', [
            'exercise_name' => 'Deadlift',
            'canonical_name' => 'deadlift',
            'date' => '2026-06-17',
            'log_type' => 'barbell',
            'weight_unit' => 'kg',
            'sets' => [['weight' => 140, 'reps' => 5]],
        ])->assertStatus(200);

        $response = $this->withHeaders($this->headers)->getJson('/api/sync/restore');
        $log = collect($response->json('logs'))->firstWhere('exerciseId', 'deadlift');

        $this->assertNotNull($log);
        $this->assertSame('kg', $log['weightUnit']);
    }
}
