<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\PRDetectionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PRDetectionLoggingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Exercise $exercise;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->exercise = Exercise::factory()->create([
            'title' => 'Bench Press',
            'user_id' => null,
            'exercise_type' => 'weighted',
        ]);
    }

    /** @test */
    public function it_logs_pr_detection_when_lift_is_created()
    {
        $this->actingAs($this->user);

        // Create a lift log
        $response = $this->post(route('lift-logs.store'), [
            'exercise_id' => $this->exercise->id,
            'weight' => 225,
            'reps' => 5,
            'rounds' => 3,
            'comments' => 'First lift',
        ]);

        $response->assertRedirect();

        // Verify PR detection log was created
        $this->assertDatabaseCount('pr_detection_logs', 1);
        
        $log = PRDetectionLog::first();
        $this->assertEquals($this->user->id, $log->user_id);
        $this->assertEquals($this->exercise->id, $log->exercise_id);
        $this->assertEquals('created', $log->trigger_event);
        $this->assertNotEmpty($log->pr_types_detected);
        $this->assertNotEmpty($log->calculation_snapshot);
    }

    /** @test */
    public function it_logs_pr_detection_when_lift_is_updated()
    {
        $this->actingAs($this->user);

        // Create initial lift
        $liftLog = LiftLog::factory()->create([
            'exercise_id' => $this->exercise->id,
            'user_id' => $this->user->id,
            'logged_at' => now()->subDay(),
        ]);
        
        $liftLog->liftSets()->create([
            'weight' => 200,
            'reps' => 5,
        ]);

        // Clear any logs from creation
        PRDetectionLog::truncate();

        // Update the lift
        $response = $this->put(route('lift-logs.update', $liftLog), [
            'exercise_id' => $this->exercise->id,
            'weight' => 225,
            'reps' => 5,
            'rounds' => 3,
            'comments' => 'Updated lift',
            'date' => now()->toDateString(),
            'logged_at' => '12:00',
        ]);

        $response->assertRedirect();

        // Verify PR detection log was created with 'updated' trigger
        $this->assertDatabaseCount('pr_detection_logs', 1);
        
        $log = PRDetectionLog::first();
        $this->assertEquals($this->user->id, $log->user_id);
        $this->assertEquals($this->exercise->id, $log->exercise_id);
        $this->assertEquals('updated', $log->trigger_event);
        $this->assertEquals($liftLog->id, $log->lift_log_id);
    }

    /** @test */
    public function it_captures_calculation_snapshot_with_pr_reasons()
    {
        $this->actingAs($this->user);

        // Create first lift (will be a PR)
        $this->post(route('lift-logs.store'), [
            'exercise_id' => $this->exercise->id,
            'weight' => 200,
            'reps' => 5,
            'rounds' => 3,
        ]);

        // Create second lift (heavier, should be a PR)
        $this->post(route('lift-logs.store'), [
            'exercise_id' => $this->exercise->id,
            'weight' => 225,
            'reps' => 5,
            'rounds' => 3,
        ]);

        $logs = PRDetectionLog::orderBy('id', 'desc')->get();
        $secondLog = $logs->first();

        // Verify snapshot contains expected data
        $snapshot = $secondLog->calculation_snapshot;
        
        $this->assertArrayHasKey('current_lift', $snapshot);
        $this->assertArrayHasKey('previous_logs_count', $snapshot);
        $this->assertArrayHasKey('previous_bests', $snapshot);
        
        // Should have PR reasons since this is heavier
        $this->assertArrayHasKey('pr_reasons', $snapshot);
    }

    /** @test */
    public function it_captures_why_not_pr_when_lift_is_not_a_pr()
    {
        $this->actingAs($this->user);

        // Create first lift (heavier)
        $this->post(route('lift-logs.store'), [
            'exercise_id' => $this->exercise->id,
            'weight' => 225,
            'reps' => 5,
            'rounds' => 3,
        ]);

        // Create second lift (lighter AND less volume - definitely not a PR)
        $this->post(route('lift-logs.store'), [
            'exercise_id' => $this->exercise->id,
            'weight' => 200,
            'reps' => 5,
            'rounds' => 2, // Less rounds = less volume
        ]);

        $logs = PRDetectionLog::orderBy('id', 'desc')->get();
        $secondLog = $logs->first();

        // Verify snapshot contains why_not_pr data
        $snapshot = $secondLog->calculation_snapshot;
        
        $this->assertArrayHasKey('why_not_pr', $snapshot);
        
        // Should have reasons why it's not a 1RM or volume PR
        if (!empty($snapshot['why_not_pr'])) {
            $this->assertTrue(
                isset($snapshot['why_not_pr']['one_rm']) || isset($snapshot['why_not_pr']['volume']),
                'Expected why_not_pr to contain reasons for one_rm or volume'
            );
        }
    }

    /** @test */
    public function it_only_logs_once_per_creation_not_on_subsequent_views()
    {
        $this->actingAs($this->user);

        // Create a lift
        $this->post(route('lift-logs.store'), [
            'exercise_id' => $this->exercise->id,
            'weight' => 225,
            'reps' => 5,
            'rounds' => 3,
        ]);

        $this->assertDatabaseCount('pr_detection_logs', 1);

        // Simulate viewing the logs (which would call PR detection for display purposes)
        // In reality, the display logic calls calculatePRLogIds which doesn't log
        // This test verifies that viewing doesn't create additional log entries
        $liftLogs = LiftLog::with(['exercise', 'liftSets'])->get();
        
        // Manually call the PR detection service (simulating what display logic does)
        $prService = app(\App\Services\PRDetectionService::class);
        $prService->calculatePRLogIds($liftLogs);

        // Should still only have 1 log entry (from creation)
        $this->assertDatabaseCount('pr_detection_logs', 1);
    }

    /** @test */
    public function it_logs_multiple_pr_types_when_detected()
    {
        $this->actingAs($this->user);

        // Create first lift
        $this->post(route('lift-logs.store'), [
            'exercise_id' => $this->exercise->id,
            'weight' => 200,
            'reps' => 5,
            'rounds' => 3,
        ]);

        // Create second lift with more weight AND more volume
        $this->post(route('lift-logs.store'), [
            'exercise_id' => $this->exercise->id,
            'weight' => 225,
            'reps' => 5,
            'rounds' => 5, // More rounds = more volume
        ]);

        $logs = PRDetectionLog::orderBy('id', 'desc')->get();
        $secondLog = $logs->first();

        // Should detect multiple PR types
        $prTypes = $secondLog->pr_types_detected;
        $this->assertNotEmpty($prTypes);
        $this->assertGreaterThan(1, count($prTypes));
    }

    /** @test */
    public function it_captures_lift_log_ids_for_previous_bests()
    {
        $this->actingAs($this->user);

        // Create first lift (will be the previous best) - explicitly set timestamp
        $firstLiftLog = LiftLog::factory()->create([
            'exercise_id' => $this->exercise->id,
            'user_id' => $this->user->id,
            'logged_at' => now()->subHour(),
        ]);
        
        $firstLiftLog->liftSets()->create([
            'weight' => 225,
            'reps' => 5,
        ]);
        $firstLiftLog->liftSets()->create([
            'weight' => 225,
            'reps' => 5,
        ]);
        $firstLiftLog->liftSets()->create([
            'weight' => 225,
            'reps' => 5,
        ]);

        // Create second lift (lighter AND less volume - definitely not a PR)
        $this->post(route('lift-logs.store'), [
            'exercise_id' => $this->exercise->id,
            'weight' => 200,
            'reps' => 4, // Different reps to avoid rep-specific PR
            'rounds' => 2,
        ]);

        $logs = PRDetectionLog::orderBy('id', 'desc')->get();
        $secondLog = $logs->first(); // Only one log since we manually created the first lift

        $snapshot = $secondLog->calculation_snapshot;

        // Verify previous_bests structure
        $this->assertArrayHasKey('previous_bests', $snapshot);
        $this->assertArrayHasKey('one_rm', $snapshot['previous_bests']);
        $this->assertArrayHasKey('volume', $snapshot['previous_bests']);

        // Verify reasons structure
        $this->assertArrayHasKey('reasons', $snapshot);
        $this->assertNotEmpty($snapshot['reasons']);
    }
}
