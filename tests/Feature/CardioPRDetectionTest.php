<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\TriggersPRDetection;
use Tests\TestCase;

class CardioPRDetectionTest extends TestCase
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
            'title' => 'Run',
            'exercise_type' => 'cardio',
        ]);
    }

    /** @test */
    public function first_cardio_creates_endurance_pr()
    {
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        
        $liftLog->liftSets()->create([
            'weight' => 0,
            'distance' => 500, // 500m distance
            'distance_unit' => 'm',
            'notes' => 'First run',
        ]);

        $this->triggerPRDetection($liftLog);

        // Should create ENDURANCE PR (best single distance)
        $this->assertDatabaseHas('personal_records', [
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'lift_log_id' => $liftLog->id,
            'pr_type' => 'endurance',
            'value' => 500,
        ]);
    }

    /** @test */
    public function longer_distance_creates_endurance_pr()
    {
        // First run: 500m
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $firstLog->liftSets()->create(['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => '']);
        $this->triggerPRDetection($firstLog);

        // Second run: 800m (longer = PR)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->create(['weight' => 0, 'distance' => 800, 'distance_unit' => 'm', 'notes' => '']);
        $this->triggerPRDetection($secondLog);

        // Should create new ENDURANCE PR
        $pr = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'endurance')
            ->first();

        $this->assertNotNull($pr);
        $this->assertEquals(800, $pr->value);
        $this->assertEquals(500, $pr->previous_value);
    }

    /** @test */
    public function shorter_distance_does_not_create_endurance_pr()
    {
        // First run: 800m
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $firstLog->liftSets()->create(['weight' => 0, 'distance' => 800, 'distance_unit' => 'm', 'notes' => '']);
        $this->triggerPRDetection($firstLog);

        // Second run: 500m (shorter = not a PR)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->create(['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => '']);
        $this->triggerPRDetection($secondLog);

        // Should NOT create ENDURANCE PR
        $pr = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'endurance')
            ->first();

        $this->assertNull($pr);
    }

    /** @test */
    public function first_cardio_creates_volume_pr()
    {
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        
        // 3 rounds of 500m = 1500m total
        $liftLog->liftSets()->createMany([
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
        ]);

        $this->triggerPRDetection($liftLog);

        // Should create VOLUME PR (total distance)
        $this->assertDatabaseHas('personal_records', [
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'lift_log_id' => $liftLog->id,
            'pr_type' => 'volume',
            'value' => 1500,
        ]);
    }

    /** @test */
    public function higher_total_distance_creates_volume_pr()
    {
        // First session: 3 × 500m = 1500m total
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
        ]);
        $this->triggerPRDetection($firstLog);

        // Second session: 4 × 500m = 2000m total (higher = PR)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        // Should create new VOLUME PR
        $pr = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'volume')
            ->first();

        $this->assertNotNull($pr);
        $this->assertEquals(2000, $pr->value);
        $this->assertEquals(1500, $pr->previous_value);
    }

    /** @test */
    public function lower_total_distance_does_not_create_volume_pr()
    {
        // First session: 4 × 500m = 2000m total
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
        ]);
        $this->triggerPRDetection($firstLog);

        // Second session: 3 × 500m = 1500m total (lower = not a PR)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        // Should NOT create VOLUME PR
        $pr = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'volume')
            ->first();

        $this->assertNull($pr);
    }

    /** @test */
    public function first_cardio_creates_rep_specific_pr()
    {
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        
        // 5 rounds of 400m = 2000m total
        $liftLog->liftSets()->createMany([
            ['weight' => 0, 'distance' => 400, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 400, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 400, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 400, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 400, 'distance_unit' => 'm', 'notes' => ''],
        ]);

        $this->triggerPRDetection($liftLog);

        // Should create REP_SPECIFIC PR for 5 rounds
        $this->assertDatabaseHas('personal_records', [
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'lift_log_id' => $liftLog->id,
            'pr_type' => 'rep_specific',
            'rep_count' => 5,
            'value' => 2000,
        ]);
    }

    /** @test */
    public function better_distance_at_same_rounds_creates_rep_specific_pr()
    {
        // First session: 5 × 400m = 2000m total
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 0, 'distance' => 400, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 400, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 400, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 400, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 400, 'distance_unit' => 'm', 'notes' => ''],
        ]);
        $this->triggerPRDetection($firstLog);

        // Second session: 5 × 500m = 2500m total (better at 5 rounds = PR)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        // Should create new REP_SPECIFIC PR for 5 rounds
        $pr = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'rep_specific')
            ->where('rep_count', 5)
            ->first();

        $this->assertNotNull($pr);
        $this->assertEquals(2500, $pr->value);
        $this->assertEquals(2000, $pr->previous_value);
    }

    /** @test */
    public function different_round_counts_create_separate_rep_specific_prs()
    {
        // First session: 3 rounds
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
        ]);
        $this->triggerPRDetection($firstLog);

        // Second session: 5 rounds (different round count)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 0, 'distance' => 400, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 400, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 400, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 400, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 400, 'distance_unit' => 'm', 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        // Should have separate PRs for 3 rounds and 5 rounds
        $this->assertDatabaseHas('personal_records', [
            'exercise_id' => $this->exercise->id,
            'pr_type' => 'rep_specific',
            'rep_count' => 3,
            'value' => 1500,
        ]);

        $this->assertDatabaseHas('personal_records', [
            'exercise_id' => $this->exercise->id,
            'pr_type' => 'rep_specific',
            'rep_count' => 5,
            'value' => 2000,
        ]);
    }

    /** @test */
    public function cardio_can_achieve_multiple_pr_types_simultaneously()
    {
        // First session: 3 × 400m = 1200m total, best single 400m
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 0, 'distance' => 400, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 400, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 400, 'distance_unit' => 'm', 'notes' => ''],
        ]);
        $this->triggerPRDetection($firstLog);

        // Second session: 3 × 600m = 1800m total, best single 600m
        // This beats ENDURANCE (600 > 400), VOLUME (1800 > 1200), and REP_SPECIFIC for 3 rounds
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 0, 'distance' => 600, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 600, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 600, 'distance_unit' => 'm', 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        // Should have all three PR types
        $prs = PersonalRecord::where('lift_log_id', $secondLog->id)->get();
        
        $this->assertGreaterThanOrEqual(3, $prs->count());
        $this->assertTrue($prs->contains('pr_type', 'endurance'));
        $this->assertTrue($prs->contains('pr_type', 'volume'));
        $this->assertTrue($prs->contains('pr_type', 'rep_specific'));
    }

    /** @test */
    public function multiple_sets_tracks_best_distance_for_endurance_pr()
    {
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        
        // Multiple rounds with varying distances - should track the best (800m)
        $liftLog->liftSets()->createMany([
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 800, 'distance_unit' => 'm', 'notes' => ''], // Best
            ['weight' => 0, 'distance' => 600, 'distance_unit' => 'm', 'notes' => ''],
        ]);

        $this->triggerPRDetection($liftLog);

        // ENDURANCE PR should be 800m (the best single distance)
        $this->assertDatabaseHas('personal_records', [
            'lift_log_id' => $liftLog->id,
            'pr_type' => 'endurance',
            'value' => 800,
        ]);
    }

    /** @test */
    public function cardio_does_not_create_one_rm_pr()
    {
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        
        $liftLog->liftSets()->create([
            'weight' => 0,
            'distance' => 500,
            'distance_unit' => 'm',
            'notes' => '',
        ]);

        $this->triggerPRDetection($liftLog);

        // Should NOT create ONE_RM PR (not applicable to cardio)
        $this->assertDatabaseMissing('personal_records', [
            'lift_log_id' => $liftLog->id,
            'pr_type' => 'one_rm',
        ]);
    }

    /** @test */
    public function cardio_display_shows_distance_correctly()
    {
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        
        $liftLog->liftSets()->createMany([
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
        ]);

        $this->triggerPRDetection($liftLog);

        // Get the strategy and format display
        $strategy = $this->exercise->getTypeStrategy();
        $display = $strategy->formatWeightDisplay($liftLog);

        // Should show distance, not weight
        $this->assertEquals('500m', $display);
    }

    /** @test */
    public function cardio_pr_flags_are_set_correctly()
    {
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        
        $liftLog->liftSets()->createMany([
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
            ['weight' => 0, 'distance' => 500, 'distance_unit' => 'm', 'notes' => ''],
        ]);

        $this->triggerPRDetection($liftLog);

        // Refresh to get updated flags
        $liftLog->refresh();

        // Should be marked as a PR
        $this->assertTrue($liftLog->is_pr);
        $this->assertEquals(3, $liftLog->pr_count); // ENDURANCE, VOLUME, REP_SPECIFIC
        
        // Verify the specific PR types were created
        $prs = PersonalRecord::where('lift_log_id', $liftLog->id)->get();
        $this->assertGreaterThanOrEqual(3, $prs->count());
        $this->assertTrue($prs->contains('pr_type', 'endurance'));
        $this->assertTrue($prs->contains('pr_type', 'volume'));
        $this->assertTrue($prs->contains('pr_type', 'rep_specific'));
        
        // Should NOT have ONE_RM PR
        $this->assertFalse($prs->contains('pr_type', 'one_rm'));
    }
}
