<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalculateHistoricalPRsCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_calculates_prs_for_all_historical_lift_logs()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['exercise_type' => 'regular']);

        // Create 3 lift logs without triggering PR detection
        $log1 = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDays(3),
            'is_pr' => false,
            'pr_count' => 0,
        ]);
        $log1->liftSets()->create(['weight' => 200, 'reps' => 5]);

        $log2 = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDays(2),
            'is_pr' => false,
            'pr_count' => 0,
        ]);
        $log2->liftSets()->create(['weight' => 210, 'reps' => 5]);

        $log3 = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDays(1),
            'is_pr' => false,
            'pr_count' => 0,
        ]);
        $log3->liftSets()->create(['weight' => 205, 'reps' => 5]);

        // Verify no PRs exist yet
        $this->assertEquals(0, PersonalRecord::count());
        $this->assertEquals(0, LiftLog::where('is_pr', true)->count());

        // Run the command
        $this->artisan('prs:calculate-historical --force')
            ->assertExitCode(0);

        // Verify PRs were calculated
        $this->assertGreaterThan(0, PersonalRecord::count());
        
        // log1 and log2 should be PRs
        $log1->refresh();
        $log2->refresh();
        $log3->refresh();
        
        $this->assertTrue($log1->is_pr);
        $this->assertTrue($log2->is_pr);
        $this->assertFalse($log3->is_pr); // Lighter than log2
    }

    /** @test */
    public function it_supports_dry_run_mode()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['exercise_type' => 'regular']);

        $log = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'is_pr' => false,
            'pr_count' => 0,
        ]);
        $log->liftSets()->create(['weight' => 200, 'reps' => 5]);

        // Run in dry-run mode
        $this->artisan('prs:calculate-historical --dry-run')
            ->expectsOutput('DRY RUN MODE - No changes will be made')
            ->assertExitCode(0);

        // Verify no changes were made
        $this->assertEquals(0, PersonalRecord::count());
        $log->refresh();
        $this->assertFalse($log->is_pr);
    }

    /** @test */
    public function it_can_calculate_prs_for_specific_user()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $exercise = Exercise::factory()->create(['exercise_type' => 'regular']);

        // Create logs for both users
        $log1 = LiftLog::factory()->create([
            'user_id' => $user1->id,
            'exercise_id' => $exercise->id,
            'is_pr' => false,
        ]);
        $log1->liftSets()->create(['weight' => 200, 'reps' => 5]);

        $log2 = LiftLog::factory()->create([
            'user_id' => $user2->id,
            'exercise_id' => $exercise->id,
            'is_pr' => false,
        ]);
        $log2->liftSets()->create(['weight' => 200, 'reps' => 5]);

        // Run for user1 only
        $this->artisan("prs:calculate-historical --user={$user1->id} --force")
            ->assertExitCode(0);

        // Verify only user1's PRs were calculated
        $log1->refresh();
        $log2->refresh();
        
        $this->assertTrue($log1->is_pr);
        $this->assertFalse($log2->is_pr);
    }

    /** @test */
    public function it_can_calculate_prs_for_specific_exercise()
    {
        $user = User::factory()->create();
        $exercise1 = Exercise::factory()->create(['exercise_type' => 'regular']);
        $exercise2 = Exercise::factory()->create(['exercise_type' => 'regular']);

        // Create logs for both exercises
        $log1 = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise1->id,
            'is_pr' => false,
        ]);
        $log1->liftSets()->create(['weight' => 200, 'reps' => 5]);

        $log2 = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise2->id,
            'is_pr' => false,
        ]);
        $log2->liftSets()->create(['weight' => 200, 'reps' => 5]);

        // Run for exercise1 only
        $this->artisan("prs:calculate-historical --exercise={$exercise1->id} --force")
            ->assertExitCode(0);

        // Verify only exercise1's PRs were calculated
        $log1->refresh();
        $log2->refresh();
        
        $this->assertTrue($log1->is_pr);
        $this->assertFalse($log2->is_pr);
    }

    /** @test */
    public function it_handles_empty_database_gracefully()
    {
        $this->artisan('prs:calculate-historical --force')
            ->expectsOutput('No lift logs or personal records found to process.')
            ->assertExitCode(1);
    }
}
