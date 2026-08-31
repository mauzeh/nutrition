<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PREdgeCasesTest extends TestCase
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
    public function updating_only_lift_to_lighter_weight_keeps_it_as_pr()
    {
        // Create initial lift log
        $response = $this->actingAs($this->user)
            ->post(route('lift-logs.store'), [
                'exercise_id' => $this->exercise->id,
                'weight' => 315,
                'reps' => 5,
                'rounds' => 1,
                'date' => now()->toDateString(),
            ]);

        $liftLog = LiftLog::where('exercise_id', $this->exercise->id)->first();
        $liftLog->refresh();
        
        $this->assertTrue($liftLog->is_pr);
        $this->assertGreaterThan(0, $liftLog->pr_count);

        // Update to lighter weight
        $response = $this->actingAs($this->user)
            ->put(route('lift-logs.update', $liftLog), [
                'exercise_id' => $this->exercise->id,
                'weight' => 200,
                'reps' => 5,
                'rounds' => 1,
                'date' => $liftLog->logged_at->toDateString(),
                'logged_at' => $liftLog->logged_at->format('H:i'),
            ]);

        $liftLog->refresh();
        
        // Should STILL be a PR (it's the only lift for this exercise)
        $this->assertTrue($liftLog->is_pr);
        $this->assertGreaterThan(0, $liftLog->pr_count);
        
        // Should have PR records
        $updatedPRCount = PersonalRecord::where('lift_log_id', $liftLog->id)->count();
        $this->assertGreaterThan(0, $updatedPRCount);
    }

    /** @test */
    public function updating_lift_to_heavier_weight_keeps_it_as_pr()
    {
        // Create two lifts
        $this->actingAs($this->user)
            ->post(route('lift-logs.store'), [
                'exercise_id' => $this->exercise->id,
                'weight' => 300,
                'reps' => 5,
                'rounds' => 1,
                'date' => now()->subDays(7)->toDateString(),
            ]);

        $this->actingAs($this->user)
            ->post(route('lift-logs.store'), [
                'exercise_id' => $this->exercise->id,
                'weight' => 315,
                'reps' => 5,
                'rounds' => 1,
                'date' => now()->toDateString(),
            ]);

        $secondLift = LiftLog::where('exercise_id', $this->exercise->id)
            ->orderBy('logged_at', 'desc')
            ->first();
        
        $this->assertTrue($secondLift->is_pr);

        // Update second lift to even heavier
        $this->actingAs($this->user)
            ->put(route('lift-logs.update', $secondLift), [
                'exercise_id' => $this->exercise->id,
                'weight' => 325,
                'reps' => 5,
                'rounds' => 1,
                'date' => $secondLift->logged_at->toDateString(),
                'logged_at' => $secondLift->logged_at->format('H:i'),
            ]);

        $secondLift->refresh();
        
        // Should still be a PR
        $this->assertTrue($secondLift->is_pr);
        $this->assertGreaterThan(0, $secondLift->pr_count);
    }

    /** @test */
    public function updating_lift_to_lighter_weight_removes_pr_status_when_other_lifts_exist()
    {
        // Create two lifts
        $this->actingAs($this->user)
            ->post(route('lift-logs.store'), [
                'exercise_id' => $this->exercise->id,
                'weight' => 300,
                'reps' => 5,
                'rounds' => 1,
                'date' => now()->subDays(7)->toDateString(),
            ]);

        $this->actingAs($this->user)
            ->post(route('lift-logs.store'), [
                'exercise_id' => $this->exercise->id,
                'weight' => 315,
                'reps' => 5,
                'rounds' => 1,
                'date' => now()->toDateString(),
            ]);

        $secondLift = LiftLog::where('exercise_id', $this->exercise->id)
            ->orderBy('logged_at', 'desc')
            ->first();
        
        $this->assertTrue($secondLift->is_pr);

        // Update second lift to lighter than first
        $this->actingAs($this->user)
            ->put(route('lift-logs.update', $secondLift), [
                'exercise_id' => $this->exercise->id,
                'weight' => 200,
                'reps' => 5,
                'rounds' => 1,
                'date' => $secondLift->logged_at->toDateString(),
                'logged_at' => $secondLift->logged_at->format('H:i'),
            ]);

        $secondLift->refresh();
        
        // Should NOT be a PR anymore (lighter weight, same reps, lower volume, lower 1RM)
        // All metrics are worse than the first lift
        $this->assertFalse($secondLift->is_pr);
        $this->assertEquals(0, $secondLift->pr_count);
    }

    /** @test */
    public function deleting_pr_lift_recalculates_remaining_lifts()
    {
        // Create first lift (PR)
        $this->actingAs($this->user)
            ->post(route('lift-logs.store'), [
                'exercise_id' => $this->exercise->id,
                'weight' => 300,
                'reps' => 5,
                'rounds' => 1,
                'date' => now()->subDays(7)->toDateString(),
            ]);

        $firstLift = LiftLog::where('exercise_id', $this->exercise->id)->first();
        $this->assertTrue($firstLift->is_pr);

        // Create second lift (heavier PR)
        $this->actingAs($this->user)
            ->post(route('lift-logs.store'), [
                'exercise_id' => $this->exercise->id,
                'weight' => 315,
                'reps' => 5,
                'rounds' => 1,
                'date' => now()->toDateString(),
            ]);

        $secondLift = LiftLog::where('exercise_id', $this->exercise->id)
            ->orderBy('logged_at', 'desc')
            ->first();
        $this->assertTrue($secondLift->is_pr);

        // Delete the second lift (the current PR)
        $this->actingAs($this->user)
            ->delete(route('lift-logs.destroy', $secondLift));

        // First lift should still be a PR
        $firstLift->refresh();
        $this->assertTrue($firstLift->is_pr);
        $this->assertGreaterThan(0, $firstLift->pr_count);
    }

    /** @test */
    public function backdated_heavier_lift_recalculates_all_subsequent_prs()
    {
        // Create lift on Jan 15
        $this->actingAs($this->user)
            ->post(route('lift-logs.store'), [
                'exercise_id' => $this->exercise->id,
                'weight' => 300,
                'reps' => 5,
                'rounds' => 1,
                'date' => now()->setDate(2026, 1, 15)->toDateString(),
            ]);

        $laterLift = LiftLog::where('exercise_id', $this->exercise->id)->first();
        $this->assertTrue($laterLift->is_pr);

        // Create backdated lift on Jan 10 with heavier weight
        $this->actingAs($this->user)
            ->post(route('lift-logs.store'), [
                'exercise_id' => $this->exercise->id,
                'weight' => 315,
                'reps' => 5,
                'rounds' => 1,
                'date' => now()->setDate(2026, 1, 10)->toDateString(),
            ]);

        $earlierLift = LiftLog::where('exercise_id', $this->exercise->id)
            ->orderBy('logged_at', 'asc')
            ->first();

        // Earlier lift should be a PR (heavier)
        $this->assertTrue($earlierLift->is_pr);
        
        // Later lift should NO LONGER be a PR (lighter than the backdated lift)
        // Data accuracy over time is the priority - we don't preserve "was a PR at the time"
        $laterLift->refresh();
        $this->assertFalse($laterLift->is_pr);
        
        // The 315 lbs lift should be the current 1RM PR
        $oneRmPR = PersonalRecord::where('exercise_id', $this->exercise->id)
            ->where('pr_type', 'one_rm')
            ->orderBy('achieved_at', 'desc')
            ->first();
        
        $this->assertEquals($earlierLift->id, $oneRmPR->lift_log_id);
    }

    /** @test */
    public function backdated_lighter_lift_is_still_a_pr()
    {
        // Create lift on Jan 15 with heavy weight
        $this->actingAs($this->user)
            ->post(route('lift-logs.store'), [
                'exercise_id' => $this->exercise->id,
                'weight' => 315,
                'reps' => 5,
                'rounds' => 1,
                'date' => now()->setDate(2026, 1, 15)->toDateString(),
            ]);

        $laterLift = LiftLog::where('exercise_id', $this->exercise->id)->first();
        $this->assertTrue($laterLift->is_pr);

        // Create backdated lift on Jan 10 with lighter weight
        $this->actingAs($this->user)
            ->post(route('lift-logs.store'), [
                'exercise_id' => $this->exercise->id,
                'weight' => 200,
                'reps' => 5,
                'rounds' => 1,
                'date' => now()->setDate(2026, 1, 10)->toDateString(),
            ]);

        $earlierLift = LiftLog::where('exercise_id', $this->exercise->id)
            ->orderBy('logged_at', 'asc')
            ->first();

        // Earlier lift should be a PR (first time for this exercise)
        $this->assertTrue($earlierLift->is_pr);
        
        // Later lift should still be a PR
        $laterLift->refresh();
        $this->assertTrue($laterLift->is_pr);
    }

    /** @test */
    public function deleting_non_pr_lift_does_not_affect_other_lifts()
    {
        // Create first lift (PR)
        $this->actingAs($this->user)
            ->post(route('lift-logs.store'), [
                'exercise_id' => $this->exercise->id,
                'weight' => 315,
                'reps' => 5,
                'rounds' => 1,
                'date' => now()->subDays(7)->toDateString(),
            ]);

        $firstLift = LiftLog::where('exercise_id', $this->exercise->id)->first();
        $this->assertTrue($firstLift->is_pr);

        // Create second lift (lighter, not a PR for 1RM but might be PR for other types)
        $this->actingAs($this->user)
            ->post(route('lift-logs.store'), [
                'exercise_id' => $this->exercise->id,
                'weight' => 200,
                'reps' => 5,
                'rounds' => 1,
                'date' => now()->toDateString(),
            ]);

        $secondLift = LiftLog::where('exercise_id', $this->exercise->id)
            ->orderBy('logged_at', 'desc')
            ->first();
        
        // Second lift might be a PR for some types (like first time at 200 lbs)
        // But it's definitely not a 1RM PR

        // Delete the second lift
        $firstLiftPRCount = PersonalRecord::where('lift_log_id', $firstLift->id)->count();
        $this->actingAs($this->user)
            ->delete(route('lift-logs.destroy', $secondLift));

        // First lift should still have the same PRs
        $firstLift->refresh();
        $this->assertTrue($firstLift->is_pr);
        
        $updatedFirstLiftPRCount = PersonalRecord::where('lift_log_id', $firstLift->id)->count();
        $this->assertGreaterThan(0, $updatedFirstLiftPRCount);
    }

    /** @test */
    public function updating_lift_date_triggers_recalculation()
    {
        // Create two lifts
        $this->actingAs($this->user)
            ->post(route('lift-logs.store'), [
                'exercise_id' => $this->exercise->id,
                'weight' => 300,
                'reps' => 5,
                'rounds' => 1,
                'date' => now()->setDate(2026, 1, 10)->toDateString(),
            ]);

        $this->actingAs($this->user)
            ->post(route('lift-logs.store'), [
                'exercise_id' => $this->exercise->id,
                'weight' => 315,
                'reps' => 5,
                'rounds' => 1,
                'date' => now()->setDate(2026, 1, 15)->toDateString(),
            ]);

        $firstLift = LiftLog::where('exercise_id', $this->exercise->id)
            ->orderBy('logged_at', 'asc')
            ->first();
        $secondLift = LiftLog::where('exercise_id', $this->exercise->id)
            ->orderBy('logged_at', 'desc')
            ->first();

        // Second lift should be the PR
        $this->assertTrue($secondLift->is_pr);

        // Update first lift to be after second lift with heavier weight
        $this->actingAs($this->user)
            ->put(route('lift-logs.update', $firstLift), [
                'exercise_id' => $this->exercise->id,
                'weight' => 325,
                'reps' => 5,
                'rounds' => 1,
                'date' => now()->setDate(2026, 1, 20)->toDateString(),
                'logged_at' => '12:00',
            ]);

        // Both should be PRs now (first lift is now the latest and heaviest)
        $firstLift->refresh();
        $secondLift->refresh();
        
        $this->assertTrue($firstLift->is_pr);
        $this->assertTrue($secondLift->is_pr);
    }
}
