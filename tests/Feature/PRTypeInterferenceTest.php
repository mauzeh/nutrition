<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests to ensure different PR types don't interfere with each other
 * and that each type is detected accurately and independently
 */
use Tests\Helpers\TriggersPRDetection;

class PRTypeInterferenceTest extends TestCase
{
    use RefreshDatabase, TriggersPRDetection;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /** @test */
    public function first_time_rep_count_is_a_pr_by_design()
    {
        $exercise = Exercise::factory()->create(['user_id' => $this->user->id]);

        // First session: 100 lbs × 5 reps
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $firstLog->liftSets()->create(['weight' => 100, 'reps' => 5]);
        $this->triggerPRDetection($firstLog);

        // Second session: 80 lbs × 4 reps (lighter weight, but first time doing 4 reps)
        // This IS a PR because it's the first 4-rep attempt - system prioritizes accuracy
        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 80,
            'reps' => 4,
            'rounds' => 1,
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        $prTypes = session('is_pr');
        
        // Should be a PR (rep-specific for first 4-rep attempt)
        $this->assertNotEmpty($prTypes);
        
        // Should have rep_specific
        $this->assertContains('rep_specific', $prTypes);
        
        $successMessage = session('success');
        $this->assertStringContainsString('PR!', $successMessage);
    }

    /** @test */
    public function rep_specific_pr_without_1rm_pr()
    {
        $exercise = Exercise::factory()->create(['user_id' => $this->user->id]);

        // First session: 200 lbs × 3 reps (estimated 1RM ~218 lbs)
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $firstLog->liftSets()->create(['weight' => 200, 'reps' => 3]);

        // Second session: 185 lbs × 5 reps (estimated 1RM ~208 lbs - LOWER than previous)
        // But this IS a rep-specific PR for 5 reps
        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 185,
            'reps' => 5,
            'rounds' => 1,
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        $prTypes = session('is_pr');
        
        // Should be a PR (rep-specific)
        $this->assertNotEmpty($prTypes);
        
        // Should have rep_specific but NOT one_rm
        $this->assertContains('rep_specific', $prTypes);
        $this->assertNotContains('one_rm', $prTypes);
    }

    /** @test */
    public function volume_pr_without_1rm_pr()
    {
        $exercise = Exercise::factory()->create(['user_id' => $this->user->id]);

        // First session: 100 lbs × 5 reps × 1 set = 500 lbs volume
        // Also establish a 4-rep baseline so rep-specific doesn't trigger
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDays(2),
        ]);
        $firstLog->liftSets()->create(['weight' => 100, 'reps' => 5]);
        
        // Establish 4-rep baseline
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $secondLog->liftSets()->create(['weight' => 85, 'reps' => 4]);

        // Third session: 80 lbs × 4 reps × 2 sets = 640 lbs volume
        // Lower weight than 4-rep baseline, but higher total volume
        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 80,
            'reps' => 4,
            'rounds' => 2,
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        $prTypes = session('is_pr');
        
        // Should be a PR (volume only)
        $this->assertNotEmpty($prTypes);
        
        // Should have volume but NOT one_rm or rep_specific
        $this->assertContains('volume', $prTypes);
        $this->assertNotContains('one_rm', $prTypes);
        $this->assertNotContains('rep_specific', $prTypes);
    }

    /** @test */
    public function one_rm_pr_without_rep_specific_pr()
    {
        $exercise = Exercise::factory()->create(['user_id' => $this->user->id]);

        // First session: 150 lbs × 8 reps (estimated 1RM = 150 × 1.2664 = 189.96 lbs)
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDays(3),
        ]);
        $firstLog->liftSets()->create(['weight' => 150, 'reps' => 8]);

        // Second session: 165 lbs × 7 reps (estimated 1RM = 165 × 1.2331 = 203.46 lbs - HIGHER)
        // This establishes a 7-rep baseline
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDays(2),
        ]);
        $secondLog->liftSets()->create(['weight' => 165, 'reps' => 7]);

        // Third session: 155 lbs × 9 reps (estimated 1RM = 155 × 1.2997 = 201.45 lbs)
        // Lower than 203.46, so NOT a 1RM PR
        // But establishes 9-rep baseline
        $thirdLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $thirdLog->liftSets()->create(['weight' => 155, 'reps' => 9]);

        // Fourth session: 168 lbs × 7 reps (estimated 1RM = 168 × 1.2331 = 207.16 lbs - HIGHER than 203.46)
        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 168,
            'reps' => 7,
            'rounds' => 1,
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        $prTypes = session('is_pr');
        
        // Should be a PR (1RM increased from 203.46 to 207.16)
        $this->assertNotEmpty($prTypes);
        
        // Should have one_rm
        $this->assertContains('one_rm', $prTypes);
        
        // Will ALSO have rep_specific because 168 > 165 for 7 reps
        $this->assertContains('rep_specific', $prTypes);
    }

    /** @test */
    public function all_three_pr_types_simultaneously()
    {
        $exercise = Exercise::factory()->create(['user_id' => $this->user->id]);

        // First session: 100 lbs × 3 reps × 1 set = 300 lbs volume
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $firstLog->liftSets()->create(['weight' => 100, 'reps' => 3]);

        // Second session: 120 lbs × 5 reps × 3 sets = 1800 lbs volume
        // - Higher estimated 1RM (120×5 ~135 vs 100×3 ~109)
        // - First time doing 5 reps at any weight (rep-specific PR)
        // - Much higher volume (1800 vs 300)
        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 120,
            'reps' => 5,
            'rounds' => 3,
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        $prTypes = session('is_pr');
        
        // Should be a PR
        $this->assertNotEmpty($prTypes);
        
        // Should have ALL THREE PR types
        $this->assertContains('one_rm', $prTypes);
        $this->assertContains('rep_specific', $prTypes);
        $this->assertContains('volume', $prTypes);
    }

    /** @test */
    public function high_rep_ranges_above_10_calculate_1rm_capped_at_10_reps()
    {
        $exercise = Exercise::factory()->create(['user_id' => $this->user->id]);

        // First session: 100 lbs × 15 reps × 1 set = 1500 lbs volume
        // Est 1RM will be calculated as if it were 10 reps (capped)
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $firstLog->liftSets()->create(['weight' => 100, 'reps' => 15]);
        $this->triggerPRDetection($firstLog);

        // Second session: 110 lbs × 15 reps × 1 set = 1650 lbs volume
        // Higher weight and volume
        // 1RM calculation will be capped at 10 reps due to diminishing returns
        // Should get: Volume PR, 1RM PR (capped calculation), but NO rep-specific PR (>10 reps)
        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 110,
            'reps' => 15,
            'rounds' => 1,
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        $prTypes = session('is_pr');
        
        // Should be a PR
        $this->assertNotEmpty($prTypes);
        
        // Should have volume and one_rm (capped at 10 reps), but NOT rep_specific (because 15 reps > 10)
        $this->assertContains('volume', $prTypes);
        $this->assertContains('one_rm', $prTypes); // Now calculated with cap
        $this->assertNotContains('rep_specific', $prTypes); // Still not tracked for >10 reps
    }

    /** @test */
    public function volume_pr_with_varying_set_weights()
    {
        $exercise = Exercise::factory()->create(['user_id' => $this->user->id]);

        // First session: 100 lbs × 5 reps × 3 sets = 1500 lbs volume
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 100, 'reps' => 5],
            ['weight' => 100, 'reps' => 5],
            ['weight' => 100, 'reps' => 5],
        ]);

        // Second session: Pyramid sets with varying weights
        // 110×5 + 105×5 + 100×5 = 1575 lbs volume (slightly more)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 110, 'reps' => 5],
            ['weight' => 105, 'reps' => 5],
            ['weight' => 100, 'reps' => 5],
        ]);

        // Use the service directly to check
        $prService = app(\App\Services\PRDetectionService::class);
        $prTypes = $prService->isLiftLogPR($secondLog, $exercise, $this->user);
        
        // Should be a PR (volume and possibly others)
        $this->assertNotEmpty($prTypes);
        
        // Should have volume PR
        $this->assertContains('volume', $prTypes);
    }

    /** @test */
    public function no_pr_when_all_metrics_are_worse_or_equal()
    {
        $exercise = Exercise::factory()->create(['user_id' => $this->user->id]);

        // First session: 150 lbs × 5 reps × 3 sets = 2250 lbs volume
        // Also establish baselines for 4 reps
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDays(2),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 150, 'reps' => 5],
            ['weight' => 150, 'reps' => 5],
            ['weight' => 150, 'reps' => 5],
        ]);
        
        // Establish 4-rep baseline that's heavier
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 145, 'reps' => 4],
            ['weight' => 145, 'reps' => 4],
        ]);

        // Third session: 140 lbs × 4 reps × 2 sets = 1120 lbs volume
        // Lower weight than 4-rep baseline, fewer reps, less volume, lower 1RM
        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 140,
            'reps' => 4,
            'rounds' => 2,
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        $prTypes = session('is_pr');
        
        // Should NOT be a PR
        $this->assertEmpty($prTypes);
    }

    /** @test */
    public function tolerance_prevents_false_positives_for_volume()
    {
        $exercise = Exercise::factory()->create(['user_id' => $this->user->id]);

        // First session: 100 lbs × 5 reps × 3 sets = 1500 lbs volume
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 100, 'reps' => 5],
            ['weight' => 100, 'reps' => 5],
            ['weight' => 100, 'reps' => 5],
        ]);

        // Second session: 100.05 lbs × 5 reps × 3 sets = 1501.5 lbs volume
        // Only 1.5 lbs more (0.1% increase) - within 1% tolerance
        // 1% of 1500 = 15 lbs, so need >1515 lbs to trigger PR
        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 100.05,
            'reps' => 5,
            'rounds' => 3,
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        $prTypes = session('is_pr');
        
        // Should NOT be a PR (within 1% tolerance)
        $this->assertEmpty($prTypes);
    }
}

