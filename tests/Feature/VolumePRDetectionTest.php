<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VolumePRDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /** @test */
    public function volume_pr_is_detected_when_total_volume_increases()
    {
        $exercise = Exercise::factory()->create(['user_id' => $this->user->id]);

        // First session: 100 lbs × 5 reps × 2 sets = 1000 lbs volume
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 100, 'reps' => 5],
            ['weight' => 100, 'reps' => 5],
        ]);

        // Second session: 90 lbs × 6 reps × 3 sets = 1620 lbs volume (lighter weight but more volume)
        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 90,
            'reps' => 6,
            'rounds' => 3,
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        // Should be marked as a PR (volume PR and possibly rep PR)
        $prTypes = session('is_pr');
        $this->assertNotEmpty($prTypes);
        
        // Should have volume PR flag
        $this->assertContains('volume', $prTypes);
        
        $successMessage = session('success');
        $this->assertStringContainsString('PR!', $successMessage);
    }

    /** @test */
    public function volume_pr_is_not_detected_when_volume_decreases()
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

        // Second session: 90 lbs × 5 reps × 3 sets = 1350 lbs volume (less volume)
        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 90,
            'reps' => 5,
            'rounds' => 3,
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        // Should NOT be marked as a PR
        $prTypes = session('is_pr');
        $this->assertEmpty($prTypes);
        
        $successMessage = session('success', '');
        $this->assertStringNotContainsString('PR!', $successMessage);
    }

    /** @test */
    public function lift_can_be_both_1rm_pr_and_volume_pr()
    {
        $exercise = Exercise::factory()->create(['user_id' => $this->user->id]);

        // First session: 100 lbs × 5 reps × 2 sets
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 100, 'reps' => 5],
            ['weight' => 100, 'reps' => 5],
        ]);

        // Second session: 120 lbs × 5 reps × 3 sets (heavier weight AND more volume)
        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 120,
            'reps' => 5,
            'rounds' => 3,
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        // Should be marked as a PR
        $prTypes = session('is_pr');
        $this->assertNotEmpty($prTypes);
        
        // Should have both 1RM and Volume PR flags
        $this->assertContains('one_rm', $prTypes);
        $this->assertContains('volume', $prTypes);
    }

    /** @test */
    public function first_lift_is_both_1rm_and_volume_pr()
    {
        $exercise = Exercise::factory()->create(['user_id' => $this->user->id]);

        // First ever lift
        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 100,
            'reps' => 5,
            'rounds' => 3,
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        // Should be marked as a PR
        $prTypes = session('is_pr');
        $this->assertNotEmpty($prTypes);
        
        // First lift should have both 1RM and Volume PR flags
        $this->assertContains('one_rm', $prTypes);
        $this->assertContains('volume', $prTypes);
    }
}

