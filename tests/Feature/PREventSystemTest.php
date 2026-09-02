<?php

namespace Tests\Feature;

use App\Events\LiftLogCompleted;
use App\Listeners\DetectAndRecordPRs;
use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PREventSystemTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Exercise $exercise;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Bench Press',
            'exercise_type' => 'barbell',
        ]);
    }

    /** @test */
    public function lift_log_completed_event_is_dispatched_on_creation()
    {
        Event::fake([LiftLogCompleted::class]);

        $response = $this->actingAs($this->user)
            ->post(route('lift-logs.store'), [
                'exercise_id' => $this->exercise->id,
                'weight' => 225,
                'reps' => 5,
                'rounds' => 3,
                'date' => now()->toDateString(),
            ]);

        $response->assertRedirect();

        Event::assertDispatched(LiftLogCompleted::class, function ($event) {
            return $event->isUpdate === false;
        });
    }

    /** @test */
    public function lift_log_completed_event_is_dispatched_on_update()
    {
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
        ]);
        
        $liftLog->liftSets()->create([
            'weight' => 200,
            'reps' => 5,
        ]);

        Event::fake([LiftLogCompleted::class]);

        $response = $this->actingAs($this->user)
            ->put(route('lift-logs.update', $liftLog), [
                'exercise_id' => $this->exercise->id,
                'weight' => 225,
                'reps' => 5,
                'rounds' => 3,
                'date' => $liftLog->logged_at->toDateString(),
                'logged_at' => $liftLog->logged_at->format('H:i'),
            ]);

        $response->assertRedirect();

        Event::assertDispatched(LiftLogCompleted::class, function ($event) {
            return $event->isUpdate === true;
        });
    }

    /** @test */
    public function listener_creates_personal_records_for_first_lift()
    {
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
        ]);
        
        $liftLog->liftSets()->create([
            'weight' => 225,
            'reps' => 5,
        ]);

        $event = new LiftLogCompleted($liftLog, isUpdate: false);
        $listener = app(DetectAndRecordPRs::class);
        $listener->handle($event);

        // Should create PRs for 1RM, volume, and rep-specific
        $this->assertDatabaseHas('personal_records', [
            'lift_log_id' => $liftLog->id,
            'pr_type' => 'one_rm',
        ]);

        $this->assertDatabaseHas('personal_records', [
            'lift_log_id' => $liftLog->id,
            'pr_type' => 'volume',
        ]);

        $this->assertDatabaseHas('personal_records', [
            'lift_log_id' => $liftLog->id,
            'pr_type' => 'rep_specific',
            'rep_count' => 5,
        ]);
    }

    /** @test */
    public function listener_updates_lift_log_pr_flags()
    {
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'is_pr' => false,
            'pr_count' => 0,
        ]);
        
        $liftLog->liftSets()->create([
            'weight' => 225,
            'reps' => 5,
        ]);

        $event = new LiftLogCompleted($liftLog, isUpdate: false);
        $listener = app(DetectAndRecordPRs::class);
        $listener->handle($event);

        $liftLog->refresh();
        
        $this->assertTrue($liftLog->is_pr);
        $this->assertGreaterThan(0, $liftLog->pr_count);
    }

    /** @test */
    public function listener_creates_pr_with_previous_pr_reference()
    {
        // Create first lift
        $firstLift = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subDays(7),
        ]);
        
        $firstLift->liftSets()->create([
            'weight' => 200,
            'reps' => 5,
        ]);

        // Trigger PR detection for first lift
        $event1 = new LiftLogCompleted($firstLift, isUpdate: false);
        $listener = app(DetectAndRecordPRs::class);
        $listener->handle($event1);

        $firstPR = PersonalRecord::where('lift_log_id', $firstLift->id)
            ->where('pr_type', 'one_rm')
            ->first();

        // Create second lift (heavier)
        $secondLift = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        
        $secondLift->liftSets()->create([
            'weight' => 225,
            'reps' => 5,
        ]);

        // Trigger PR detection for second lift
        $event2 = new LiftLogCompleted($secondLift, isUpdate: false);
        $listener->handle($event2);

        $secondPR = PersonalRecord::where('lift_log_id', $secondLift->id)
            ->where('pr_type', 'one_rm')
            ->first();

        $this->assertNotNull($secondPR);
        $this->assertEquals($firstPR->id, $secondPR->previous_pr_id);
        $this->assertNotNull($secondPR->previous_value);
    }

    /** @test */
    public function listener_deletes_old_prs_on_update()
    {
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
        ]);
        
        $liftLog->liftSets()->create([
            'weight' => 225,
            'reps' => 5,
        ]);

        // Create initial PRs
        $event1 = new LiftLogCompleted($liftLog, isUpdate: false);
        $listener = app(DetectAndRecordPRs::class);
        $listener->handle($event1);

        $initialPRCount = PersonalRecord::where('lift_log_id', $liftLog->id)->count();
        $this->assertGreaterThan(0, $initialPRCount);

        // Update the lift log (change weight)
        $liftLog->liftSets()->delete();
        $liftLog->liftSets()->create([
            'weight' => 250,
            'reps' => 5,
        ]);

        // Trigger update event
        $event2 = new LiftLogCompleted($liftLog, isUpdate: true);
        $listener->handle($event2);

        // Should still have PRs, but they should be recalculated
        $updatedPRCount = PersonalRecord::where('lift_log_id', $liftLog->id)->count();
        $this->assertGreaterThan(0, $updatedPRCount);
    }

    /** @test */
    public function listener_handles_non_pr_lifts_correctly()
    {
        // Create first lift (heavier)
        $firstLift = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subDays(7),
        ]);
        
        $firstLift->liftSets()->create([
            'weight' => 250,
            'reps' => 5,
        ]);

        $event1 = new LiftLogCompleted($firstLift, false);
        $listener = app(DetectAndRecordPRs::class);
        $listener->handle($event1);

        // Create second lift (lighter - NOT a PR because all metrics are worse)
        $secondLift = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        
        $secondLift->liftSets()->create([
            'weight' => 200,
            'reps' => 5,
        ]);

        $event2 = new LiftLogCompleted($secondLift, false);
        $listener->handle($event2);

        $secondLift->refresh();
        
        // Should NOT be marked as PR (lighter weight, same reps, lower volume, lower 1RM)
        $this->assertFalse($secondLift->is_pr);
        $this->assertEquals(0, $secondLift->pr_count);
        
        // Should have NO PR records
        $prCount = PersonalRecord::where('lift_log_id', $secondLift->id)->count();
        $this->assertEquals(0, $prCount);
    }

    /** @test */
    public function listener_creates_hypertrophy_prs()
    {
        // Create first lift at 200 lbs for 8 reps
        $firstLift = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subDays(7),
        ]);
        
        $firstLift->liftSets()->create([
            'weight' => 200,
            'reps' => 8,
        ]);

        $event1 = new LiftLogCompleted($firstLift, isUpdate: false);
        $listener = app(DetectAndRecordPRs::class);
        $listener->handle($event1);

        // Create second lift at 200 lbs for 10 reps (hypertrophy PR)
        $secondLift = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        
        $secondLift->liftSets()->create([
            'weight' => 200,
            'reps' => 10,
        ]);

        $event2 = new LiftLogCompleted($secondLift, isUpdate: false);
        $listener->handle($event2);

        // Should have hypertrophy PR
        $this->assertDatabaseHas('personal_records', [
            'lift_log_id' => $secondLift->id,
            'pr_type' => 'hypertrophy',
            'weight' => 200,
            'value' => 10,
        ]);
    }

    /** @test */
    public function listener_handles_bodyweight_exercises_correctly()
    {
        $bodyweightExercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Pull-ups',
            'exercise_type' => 'bodyweight',
        ]);

        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $bodyweightExercise->id,
        ]);
        
        $liftLog->liftSets()->create([
            'weight' => 0,
            'reps' => 10,
        ]);

        $event = new LiftLogCompleted($liftLog, isUpdate: false);
        $listener = app(DetectAndRecordPRs::class);
        $listener->handle($event);

        // Bodyweight exercises support Volume PRs (using total reps for pure bodyweight)
        $prCount = PersonalRecord::where('lift_log_id', $liftLog->id)->count();
        $this->assertEquals(1, $prCount, 'First bodyweight lift should create a Volume PR');
        
        // Verify it's a bodyweight_volume PR
        $pr = PersonalRecord::where('lift_log_id', $liftLog->id)->first();
        $this->assertEquals('bodyweight_volume', $pr->pr_type);
        $this->assertEquals(10, $pr->value); // Total reps
        
        $liftLog->refresh();
        $this->assertTrue($liftLog->is_pr);
        $this->assertEquals(1, $liftLog->pr_count);
    }

    /** @test */
    public function end_to_end_pr_creation_via_http()
    {
        $response = $this->actingAs($this->user)
            ->post(route('lift-logs.store'), [
                'exercise_id' => $this->exercise->id,
                'weight' => 225,
                'reps' => 5,
                'rounds' => 3,
                'date' => now()->toDateString(),
            ]);

        $response->assertRedirect();

        $liftLog = LiftLog::where('exercise_id', $this->exercise->id)->first();
        
        // Should have PR records created
        $this->assertGreaterThan(0, PersonalRecord::where('lift_log_id', $liftLog->id)->count());
        
        // Should have flags set
        $this->assertTrue($liftLog->is_pr);
        $this->assertGreaterThan(0, $liftLog->pr_count);
    }
}
