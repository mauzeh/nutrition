<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\TriggersPRDetection;
use Tests\TestCase;

class DensityPRDetectionTest extends TestCase
{
    use RefreshDatabase, TriggersPRDetection;

    protected User $user;
    protected Exercise $exercise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Back Rack Lunge',
            'exercise_type' => 'regular',
        ]);
    }

    /** @test */
    public function first_lift_does_not_create_density_pr()
    {
        // First time doing this exercise - no previous data to compare
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        
        $liftLog->liftSets()->create([
            'weight' => 145,
            'reps' => 10,
            'notes' => '',
        ]);

        $this->triggerPRDetection($liftLog);

        // Should NOT create DENSITY PR (no previous data to compare)
        $this->assertDatabaseMissing('personal_records', [
            'lift_log_id' => $liftLog->id,
            'pr_type' => 'density',
        ]);
    }

    /** @test */
    public function more_sets_at_same_weight_creates_density_pr()
    {
        // First session: 1 set of 145 lbs × 10 reps
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $firstLog->liftSets()->create(['weight' => 145, 'reps' => 10, 'notes' => '']);
        $this->triggerPRDetection($firstLog);

        // Second session: 2 sets of 145 lbs × 10 reps (more sets = PR)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        // Should create DENSITY PR
        $pr = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'density')
            ->first();

        $this->assertNotNull($pr);
        $this->assertEquals(145, $pr->weight);
        $this->assertEquals(2, $pr->value); // 2 sets
        $this->assertEquals(1, $pr->previous_value); // previously 1 set
    }

    /** @test */
    public function same_number_of_sets_does_not_create_density_pr()
    {
        // First session: 2 sets of 145 lbs × 10 reps
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
        ]);
        $this->triggerPRDetection($firstLog);

        // Second session: 2 sets of 145 lbs × 10 reps (same = not a PR)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        // Should NOT create DENSITY PR
        $pr = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'density')
            ->first();

        $this->assertNull($pr);
    }

    /** @test */
    public function fewer_sets_does_not_create_density_pr()
    {
        // First session: 3 sets of 145 lbs × 10 reps
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
        ]);
        $this->triggerPRDetection($firstLog);

        // Second session: 2 sets of 145 lbs × 10 reps (fewer = not a PR)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        // Should NOT create DENSITY PR
        $pr = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'density')
            ->first();

        $this->assertNull($pr);
    }

    /** @test */
    public function density_pr_is_weight_specific()
    {
        // First session: 2 sets of 145 lbs, 1 set of 135 lbs
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
            ['weight' => 135, 'reps' => 10, 'notes' => ''],
        ]);
        $this->triggerPRDetection($firstLog);

        // Second session: 3 sets of 145 lbs (more sets at 145 = PR)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        // Should create DENSITY PR for 145 lbs
        $pr = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'density')
            ->where('weight', 145)
            ->first();

        $this->assertNotNull($pr);
        $this->assertEquals(3, $pr->value); // 3 sets at 145 lbs
        $this->assertEquals(2, $pr->previous_value); // previously 2 sets
    }

    /** @test */
    public function multiple_density_prs_can_be_achieved_simultaneously()
    {
        // First session: 1 set of 145 lbs, 1 set of 135 lbs
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
            ['weight' => 135, 'reps' => 10, 'notes' => ''],
        ]);
        $this->triggerPRDetection($firstLog);

        // Second session: 2 sets of 145 lbs, 2 sets of 135 lbs (both are PRs)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
            ['weight' => 135, 'reps' => 10, 'notes' => ''],
            ['weight' => 135, 'reps' => 10, 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        // Should create DENSITY PRs for both weights
        $prs = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'density')
            ->get();

        $this->assertNotEmpty($prs);
        $this->assertTrue($prs->contains('weight', 145));
        $this->assertTrue($prs->contains('weight', 135));
    }

    /** @test */
    public function density_pr_can_be_achieved_alongside_other_pr_types()
    {
        // First session: 1 set of 145 lbs × 10 reps
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $firstLog->liftSets()->create(['weight' => 145, 'reps' => 10, 'notes' => '']);
        $this->triggerPRDetection($firstLog);

        // Second session: 2 sets of 145 lbs × 10 reps
        // This should create: DENSITY PR (more sets), VOLUME PR (more total volume)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        // Should have both DENSITY and VOLUME PRs
        $prs = PersonalRecord::where('lift_log_id', $secondLog->id)->get();
        
        $this->assertTrue($prs->contains('pr_type', 'density'));
        $this->assertTrue($prs->contains('pr_type', 'volume'));
    }

    /** @test */
    public function density_pr_respects_weight_tolerance()
    {
        // First session: 1 set of 145 lbs
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $firstLog->liftSets()->create(['weight' => 145, 'reps' => 10, 'notes' => '']);
        $this->triggerPRDetection($firstLog);

        // Second session: 2 sets of 145.25 lbs (within 0.5 lb tolerance)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 145.25, 'reps' => 10, 'notes' => ''],
            ['weight' => 145.25, 'reps' => 10, 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        // Should create DENSITY PR (weights are within tolerance)
        $pr = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'density')
            ->first();

        $this->assertNotNull($pr);
        $this->assertEquals(145.25, $pr->weight);
        $this->assertEquals(2, $pr->value);
    }

    /** @test */
    public function density_pr_display_shows_correct_format()
    {
        // First session: 1 set of 145 lbs
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $firstLog->liftSets()->create(['weight' => 145, 'reps' => 10, 'notes' => '']);
        $this->triggerPRDetection($firstLog);

        // Second session: 2 sets of 145 lbs
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        // Get the PR and format it
        $pr = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'density')
            ->first();

        $strategy = $this->exercise->getTypeStrategy();
        $config = new \App\Services\LiftLogTableRowBuilder\RowConfig();
        $assembled = \App\Services\LiftLogTableRowBuilder\PRRecordsComponentAssembler::assemble($secondLog, $config);
        $records = $assembled[0]['data']['records'] ?? [];
        $densityRecord = collect($records)->firstWhere('label', 'Max Sets @ Weight');

        $this->assertNotNull($densityRecord);
        $this->assertEquals('1 set', $densityRecord['value']);
        $this->assertEquals('2 sets', $densityRecord['comparison']);
    }

    /** @test */
    public function johns_back_rack_lunge_scenario()
    {
        // Simulate John's actual scenario from the bug report
        
        // Week 1: 1 set of 145 lbs × 10 reps
        $week1Log = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $week1Log->liftSets()->create(['weight' => 145, 'reps' => 10, 'notes' => '']);
        $this->triggerPRDetection($week1Log);

        // Week 2: 2 sets of 145 lbs × 10 reps (same weight, more sets)
        $week2Log = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $week2Log->liftSets()->createMany([
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
            ['weight' => 145, 'reps' => 10, 'notes' => ''],
        ]);
        $this->triggerPRDetection($week2Log);

        // Should create DENSITY PR
        $densityPR = PersonalRecord::where('lift_log_id', $week2Log->id)
            ->where('pr_type', 'density')
            ->first();

        $this->assertNotNull($densityPR, 'Density PR should be created');
        $this->assertEquals(145, $densityPR->weight);
        $this->assertEquals(2, $densityPR->value); // 2 sets
        $this->assertEquals(1, $densityPR->previous_value); // previously 1 set

        // Should also create VOLUME PR (2900 lbs > 1450 lbs)
        $volumePR = PersonalRecord::where('lift_log_id', $week2Log->id)
            ->where('pr_type', 'volume')
            ->first();

        $this->assertNotNull($volumePR, 'Volume PR should be created');
        $this->assertEquals(2900, $volumePR->value);
        $this->assertEquals(1450, $volumePR->previous_value);

        // Lift log should be marked as PR
        $week2Log->refresh();
        $this->assertTrue($week2Log->is_pr);
        $this->assertGreaterThanOrEqual(2, $week2Log->pr_count); // At least density + volume
    }
}
