<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\LiftSet;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaticHoldPRDetectionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function first_static_hold_creates_time_pr()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'L-sit',
        ]);

        // First hold: 30 seconds bodyweight
        $liftLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $liftLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 30, // 30 seconds
        ]);

        // Trigger PR detection
        event(new \App\Events\LiftLogCompleted($liftLog));

        // Should create TIME PR
        $timePR = PersonalRecord::where('lift_log_id', $liftLog->id)
            ->where('pr_type', 'time')
            ->first();

        $this->assertNotNull($timePR);
        $this->assertEquals(30, $timePR->value);
        $this->assertNull($timePR->previous_pr_id);
        $this->assertNull($timePR->previous_value);
    }

    /** @test */
    public function longer_hold_creates_time_pr()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'Plank',
        ]);

        // First hold: 45 seconds
        $firstLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $firstLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 45,
        ]);

        event(new \App\Events\LiftLogCompleted($firstLog));

        // Second hold: 60 seconds (PR!)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $secondLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 60,
        ]);

        event(new \App\Events\LiftLogCompleted($secondLog));

        // Should create TIME PR
        $timePR = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'time')
            ->first();

        $this->assertNotNull($timePR);
        $this->assertEquals(60, $timePR->value);
        $this->assertEquals(45, $timePR->previous_value);
    }

    /** @test */
    public function shorter_hold_does_not_create_time_pr()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'Front Lever',
        ]);

        // First hold: 20 seconds
        $firstLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $firstLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 20,
        ]);

        event(new \App\Events\LiftLogCompleted($firstLog));

        // Second hold: 15 seconds (not a PR)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $secondLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 15,
        ]);

        event(new \App\Events\LiftLogCompleted($secondLog));

        // Should NOT create TIME PR
        $timePR = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'time')
            ->first();

        $this->assertNull($timePR);
    }

    /** @test */
    public function weighted_static_hold_creates_time_pr()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'L-sit', // Note: Static holds are always bodyweight
        ]);

        // First hold: 30 seconds bodyweight (weight field ignored)
        $liftLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $liftLog->id,
            'weight' => 0, // Static holds are always bodyweight
            'reps' => 1,
            'time' => 30,
        ]);

        event(new \App\Events\LiftLogCompleted($liftLog));

        // Should create TIME PR
        $timePR = PersonalRecord::where('lift_log_id', $liftLog->id)
            ->where('pr_type', 'time')
            ->first();

        $this->assertNotNull($timePR);
        $this->assertEquals(30, $timePR->value); // duration
    }

    /** @test */
    public function longer_hold_creates_time_pr_regardless_of_weight_field()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'L-sit',
        ]);

        // First hold: 20 seconds
        $firstLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $firstLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 20,
        ]);

        event(new \App\Events\LiftLogCompleted($firstLog));

        // Second hold: 30 seconds (PR!)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $secondLog->id,
            'weight' => 0, // Static holds are always bodyweight
            'reps' => 1,
            'time' => 30,
        ]);

        event(new \App\Events\LiftLogCompleted($secondLog));

        // Should create TIME PR
        $timePR = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'time')
            ->first();

        $this->assertNotNull($timePR);
        $this->assertEquals(30, $timePR->value);
        $this->assertEquals(20, $timePR->previous_value);
    }

    /** @test */
    public function static_hold_can_achieve_both_time_and_density_pr_in_same_session()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'Handstand',
        ]);

        // First hold: 30 seconds bodyweight
        $firstLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $firstLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 30,
        ]);

        event(new \App\Events\LiftLogCompleted($firstLog));

        // Second hold: 45 seconds (TIME PR) + 2 sets of 30s (DENSITY PR)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $secondLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 45, // TIME PR
        ]);
        
        LiftSet::factory()->create([
            'lift_log_id' => $secondLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 30, // DENSITY PR (2 sets at 30s)
        ]);
        
        LiftSet::factory()->create([
            'lift_log_id' => $secondLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 30,
        ]);

        event(new \App\Events\LiftLogCompleted($secondLog));

        // Should create both TIME and DENSITY PRs
        $timePR = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'time')
            ->first();

        $densityPR = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'density')
            ->first();

        $this->assertNotNull($timePR);
        $this->assertNotNull($densityPR);
        $this->assertEquals(45, $timePR->value);
        $this->assertEquals(2, $densityPR->value); // 2 sets at 30s
    }

    /** @test */
    public function multiple_sets_tracks_best_hold_for_time_pr()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'Hollow Body Hold',
        ]);

        // Multiple sets with varying durations
        $liftLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $liftLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 40, // First set: 40s
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $liftLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 50, // Second set: 50s (best)
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $liftLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 35, // Third set: 35s
        ]);

        event(new \App\Events\LiftLogCompleted($liftLog));

        // Should use best hold (50s) for TIME PR
        $timePR = PersonalRecord::where('lift_log_id', $liftLog->id)
            ->where('pr_type', 'time')
            ->first();

        $this->assertNotNull($timePR);
        $this->assertEquals(50, $timePR->value);
    }

    /** @test */
    public function multiple_sets_at_different_durations_tracks_each_separately()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'Planche',
        ]);

        // First session: 1 set at 20s, 1 set at 25s
        $firstLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $firstLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 20,
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $firstLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 25,
        ]);

        event(new \App\Events\LiftLogCompleted($firstLog));

        // Second session: 2 sets at 20s, 2 sets at 25s (both are density PRs)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $secondLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 20,
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $secondLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 20,
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $secondLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 25,
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $secondLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 25,
        ]);

        event(new \App\Events\LiftLogCompleted($secondLog));

        // Should create separate density PRs for each duration
        $densityPRs = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'density')
            ->get();

        $this->assertNotEmpty($densityPRs);
        
        // Check we have PRs for both durations
        $durations = $densityPRs->pluck('rep_count')->sort()->values();
        $this->assertTrue($durations->contains(20));
        $this->assertTrue($durations->contains(25));
    }

    /** @test */
    public function static_hold_does_not_create_one_rm_pr()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'L-sit',
        ]);

        $liftLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $liftLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 30,
        ]);

        event(new \App\Events\LiftLogCompleted($liftLog));

        // Should NOT create ONE_RM PR
        $oneRMPR = PersonalRecord::where('lift_log_id', $liftLog->id)
            ->where('pr_type', 'one_rm')
            ->first();

        $this->assertNull($oneRMPR);
    }

    /** @test */
    public function first_static_hold_creates_volume_pr()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'Plank',
        ]);

        // First session: 3 sets totaling 90 seconds
        $liftLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);

        LiftSet::factory()->createMany([
            ['lift_log_id' => $liftLog->id, 'weight' => 0, 'reps' => 1, 'time' => 30],
            ['lift_log_id' => $liftLog->id, 'weight' => 0, 'reps' => 1, 'time' => 30],
            ['lift_log_id' => $liftLog->id, 'weight' => 0, 'reps' => 1, 'time' => 30],
        ]);

        event(new \App\Events\LiftLogCompleted($liftLog));

        // Should create VOLUME PR
        $volumePR = PersonalRecord::where('lift_log_id', $liftLog->id)
            ->where('pr_type', 'volume')
            ->first();

        $this->assertNotNull($volumePR);
        $this->assertEquals(90, $volumePR->value); // 30 + 30 + 30
        $this->assertNull($volumePR->previous_value);
    }

    /** @test */
    public function higher_total_volume_creates_volume_pr()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'L-sit',
        ]);

        // First session: 2 sets totaling 60 seconds
        $firstLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);

        LiftSet::factory()->createMany([
            ['lift_log_id' => $firstLog->id, 'weight' => 0, 'reps' => 1, 'time' => 30],
            ['lift_log_id' => $firstLog->id, 'weight' => 0, 'reps' => 1, 'time' => 30],
        ]);

        event(new \App\Events\LiftLogCompleted($firstLog));

        // Second session: 3 sets totaling 90 seconds (VOLUME PR!)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);

        LiftSet::factory()->createMany([
            ['lift_log_id' => $secondLog->id, 'weight' => 0, 'reps' => 1, 'time' => 30],
            ['lift_log_id' => $secondLog->id, 'weight' => 0, 'reps' => 1, 'time' => 30],
            ['lift_log_id' => $secondLog->id, 'weight' => 0, 'reps' => 1, 'time' => 30],
        ]);

        event(new \App\Events\LiftLogCompleted($secondLog));

        // Should create VOLUME PR
        $volumePR = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'volume')
            ->first();

        $this->assertNotNull($volumePR);
        $this->assertEquals(90, $volumePR->value);
        $this->assertEquals(60, $volumePR->previous_value);
    }

    /** @test */
    public function lower_total_volume_does_not_create_volume_pr()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'Plank',
        ]);

        // First session: 3 sets totaling 90 seconds
        $firstLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);

        LiftSet::factory()->createMany([
            ['lift_log_id' => $firstLog->id, 'weight' => 0, 'reps' => 1, 'time' => 30],
            ['lift_log_id' => $firstLog->id, 'weight' => 0, 'reps' => 1, 'time' => 30],
            ['lift_log_id' => $firstLog->id, 'weight' => 0, 'reps' => 1, 'time' => 30],
        ]);

        event(new \App\Events\LiftLogCompleted($firstLog));

        // Second session: 2 sets totaling 60 seconds (not a PR)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);

        LiftSet::factory()->createMany([
            ['lift_log_id' => $secondLog->id, 'weight' => 0, 'reps' => 1, 'time' => 30],
            ['lift_log_id' => $secondLog->id, 'weight' => 0, 'reps' => 1, 'time' => 30],
        ]);

        event(new \App\Events\LiftLogCompleted($secondLog));

        // Should NOT create VOLUME PR
        $volumePR = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'volume')
            ->first();

        $this->assertNull($volumePR);
    }

    /** @test */
    public function volume_pr_sums_all_sets_regardless_of_duration()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'Hollow Body Hold',
        ]);

        // First session: 2 sets totaling 50 seconds
        $firstLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);

        LiftSet::factory()->createMany([
            ['lift_log_id' => $firstLog->id, 'weight' => 0, 'reps' => 1, 'time' => 25],
            ['lift_log_id' => $firstLog->id, 'weight' => 0, 'reps' => 1, 'time' => 25],
        ]);

        event(new \App\Events\LiftLogCompleted($firstLog));

        // Second session: varying durations totaling 100 seconds (VOLUME PR!)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);

        LiftSet::factory()->createMany([
            ['lift_log_id' => $secondLog->id, 'weight' => 0, 'reps' => 1, 'time' => 40],
            ['lift_log_id' => $secondLog->id, 'weight' => 0, 'reps' => 1, 'time' => 35],
            ['lift_log_id' => $secondLog->id, 'weight' => 0, 'reps' => 1, 'time' => 25],
        ]);

        event(new \App\Events\LiftLogCompleted($secondLog));

        // Should create VOLUME PR with sum of all durations
        $volumePR = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'volume')
            ->first();

        $this->assertNotNull($volumePR);
        $this->assertEquals(100, $volumePR->value); // 40 + 35 + 25
        $this->assertEquals(50, $volumePR->previous_value); // 25 + 25
    }

    /** @test */
    public function static_hold_does_not_create_hypertrophy_pr()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'Front Lever',
        ]);

        $liftLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $liftLog->id,
            'weight' => 10,
            'reps' => 1,
            'time' => 20,
        ]);

        event(new \App\Events\LiftLogCompleted($liftLog));

        // Should NOT create HYPERTROPHY PR
        $hypertrophyPR = PersonalRecord::where('lift_log_id', $liftLog->id)
            ->where('pr_type', 'hypertrophy')
            ->first();

        $this->assertNull($hypertrophyPR);
    }

    /** @test */
    public function static_hold_display_shows_time_field_not_reps()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'L-sit',
        ]);

        // Create a lift log with 45 second hold
        $liftLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $liftLog->id,
            'weight' => 0,
            'reps' => 1,  // Always 1 for static holds
            'time' => 45, // 45 seconds
        ]);

        // Visit the mobile entry lifts page
        $response = $this->actingAs($user)->get(route('mobile-entry.lifts'));

        $response->assertStatus(200);
        
        // Should display "45s hold" not "1s hold"
        $response->assertSee('45s hold');
        $response->assertDontSee('1s hold');
        $response->assertSee('L-sit');
    }

    /** @test */
    public function static_hold_display_shows_correct_duration_with_weight()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'Weighted Plank',
        ]);

        // Create a lift log with 60 second hold and 25 lbs
        $liftLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $liftLog->id,
            'weight' => 25,
            'reps' => 1,  // Always 1 for static holds
            'time' => 60, // 60 seconds = 1 minute
        ]);

        // Visit the mobile entry lifts page
        $response = $this->actingAs($user)->get(route('mobile-entry.lifts'));

        $response->assertStatus(200);
        
        // Should display "1m hold +25 lbs" not "1s hold +25 lbs"
        $response->assertSee('1m hold');
        $response->assertSee('+25 lbs');
        $response->assertDontSee('1s hold');
        $response->assertSee('Weighted Plank');
    }

    /** @test */
    public function static_hold_last_workout_message_shows_correct_duration()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'L-sit',
        ]);

        // Create a previous lift log with 45 second hold
        $previousLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $previousLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 45, // 45 seconds
        ]);

        // Visit the lift log create page for today
        $response = $this->actingAs($user)->get(route('lift-logs.create', [
            'exercise_id' => $exercise->id,
            'date' => now()->toDateString(),
        ]));

        $response->assertStatus(200);
        
        // Should show "Last workout" message with correct duration
        $response->assertSee('Last workout');
        $response->assertSee('45s hold');
        $response->assertDontSee('0s hold'); // Bug was showing 0s
        $response->assertDontSee('1s hold'); // Should not show reps value
    }

    /** @test */
    public function static_hold_last_workout_message_shows_correct_duration_with_weight()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'Weighted Plank',
        ]);

        // Create a previous lift log with 90 second hold and 25 lbs
        $previousLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $previousLog->id,
            'weight' => 25,
            'reps' => 1,
            'time' => 90, // 90 seconds = 1m 30s
        ]);

        // Visit the lift log create page for today
        $response = $this->actingAs($user)->get(route('lift-logs.create', [
            'exercise_id' => $exercise->id,
            'date' => now()->toDateString(),
        ]));

        $response->assertStatus(200);
        
        // Should show "Last workout" message with correct duration and weight
        $response->assertSee('Last workout');
        $response->assertSee('1m 30s hold');
        $response->assertSee('+25 lbs');
    }

    /** @test */
    public function static_hold_density_pr_more_sets_at_same_weight()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'L-sit',
        ]);

        // First session: 1 set of 30s bodyweight hold
        $firstLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        LiftSet::factory()->create([
            'lift_log_id' => $firstLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 30,
        ]);
        event(new \App\Events\LiftLogCompleted($firstLog));

        // Second session: 2 sets of 30s bodyweight hold (more sets = density PR)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);
        LiftSet::factory()->createMany([
            [
                'lift_log_id' => $secondLog->id,
                'weight' => 0,
                'reps' => 1,
                'time' => 30,
            ],
            [
                'lift_log_id' => $secondLog->id,
                'weight' => 0,
                'reps' => 1,
                'time' => 30,
            ],
        ]);
        event(new \App\Events\LiftLogCompleted($secondLog));

        // Should create DENSITY PR
        $densityPR = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'density')
            ->first();

        $this->assertNotNull($densityPR);
        $this->assertEquals(0, $densityPR->weight); // Bodyweight
        $this->assertEquals(2, $densityPR->value); // 2 sets
        $this->assertEquals(1, $densityPR->previous_value); // previously 1 set
    }

    /** @test */
    public function static_hold_density_pr_tracks_by_duration_only()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'L-sit',
        ]);

        // First session: 1 set of 60s
        $firstLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        LiftSet::factory()->create([
            'lift_log_id' => $firstLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 60,
        ]);
        event(new \App\Events\LiftLogCompleted($firstLog));

        // Second session: 3 sets of 60s (more sets = density PR)
        // Note: Weight field is ignored for static holds
        $secondLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);
        LiftSet::factory()->createMany([
            [
                'lift_log_id' => $secondLog->id,
                'weight' => 0,
                'reps' => 1,
                'time' => 60,
            ],
            [
                'lift_log_id' => $secondLog->id,
                'weight' => 0,
                'reps' => 1,
                'time' => 60,
            ],
            [
                'lift_log_id' => $secondLog->id,
                'weight' => 0,
                'reps' => 1,
                'time' => 60,
            ],
        ]);
        event(new \App\Events\LiftLogCompleted($secondLog));

        // Should create DENSITY PR (tracked by duration only)
        $densityPR = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'density')
            ->first();

        $this->assertNotNull($densityPR);
        $this->assertEquals(0, $densityPR->weight); // Always bodyweight
        $this->assertEquals(60, $densityPR->rep_count); // Duration stored in rep_count
        $this->assertEquals(3, $densityPR->value); // 3 sets
        $this->assertEquals(1, $densityPR->previous_value); // previously 1 set
    }

    /** @test */
    public function static_hold_density_pr_not_created_for_same_number_of_sets()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'L-sit',
        ]);

        // First session: 2 sets of 30s
        $firstLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        LiftSet::factory()->createMany([
            ['lift_log_id' => $firstLog->id, 'weight' => 0, 'reps' => 1, 'time' => 30],
            ['lift_log_id' => $firstLog->id, 'weight' => 0, 'reps' => 1, 'time' => 30],
        ]);
        event(new \App\Events\LiftLogCompleted($firstLog));

        // Second session: 2 sets of 30s (same = not a PR)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);
        LiftSet::factory()->createMany([
            ['lift_log_id' => $secondLog->id, 'weight' => 0, 'reps' => 1, 'time' => 30],
            ['lift_log_id' => $secondLog->id, 'weight' => 0, 'reps' => 1, 'time' => 30],
        ]);
        event(new \App\Events\LiftLogCompleted($secondLog));

        // Should NOT create DENSITY PR
        $densityPR = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'density')
            ->first();

        $this->assertNull($densityPR);
    }

    /** @test */
    public function static_hold_can_achieve_both_time_and_density_pr()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'L-sit',
        ]);

        // First session: 1 set of 30s
        $firstLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        LiftSet::factory()->create([
            'lift_log_id' => $firstLog->id,
            'weight' => 0,
            'reps' => 1,
            'time' => 30,
        ]);
        event(new \App\Events\LiftLogCompleted($firstLog));

        // Second session: 2 sets of 30s (same duration, more sets) + 1 set of 45s (longer hold)
        // This should create both TIME PR (45s) and DENSITY PR (2 sets @ 30s)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);
        LiftSet::factory()->createMany([
            ['lift_log_id' => $secondLog->id, 'weight' => 0, 'reps' => 1, 'time' => 30],
            ['lift_log_id' => $secondLog->id, 'weight' => 0, 'reps' => 1, 'time' => 30],
            ['lift_log_id' => $secondLog->id, 'weight' => 0, 'reps' => 1, 'time' => 45],
        ]);
        event(new \App\Events\LiftLogCompleted($secondLog));

        // Should have both TIME and DENSITY PRs
        $prs = PersonalRecord::where('lift_log_id', $secondLog->id)->get();
        
        $this->assertTrue($prs->contains('pr_type', 'time'));
        $this->assertTrue($prs->contains('pr_type', 'density'));
        
        $secondLog->refresh();
        $this->assertTrue($secondLog->is_pr);
        $this->assertGreaterThanOrEqual(2, $secondLog->pr_count);
    }
}
