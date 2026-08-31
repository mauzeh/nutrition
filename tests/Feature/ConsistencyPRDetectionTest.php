<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\TriggersPRDetection;
use Tests\TestCase;

class ConsistencyPRDetectionTest extends TestCase
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
            'title' => 'L-sit',
            'exercise_type' => 'static_hold',
        ]);
    }

    /** @test */
    public function first_multi_set_session_creates_consistency_pr()
    {
        // First time doing multiple sets - should create consistency PR
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        
        $liftLog->liftSets()->createMany([
            ['weight' => 0, 'reps' => 1, 'time' => 15, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 15, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 15, 'notes' => ''],
        ]);

        $this->triggerPRDetection($liftLog);

        // Should create CONSISTENCY PR (first multi-set session)
        $pr = PersonalRecord::where('lift_log_id', $liftLog->id)
            ->where('pr_type', 'consistency')
            ->first();

        $this->assertNotNull($pr);
        $this->assertEquals(15, $pr->value); // Min hold of 15s
        $this->assertEquals(3, $pr->rep_count); // 3 sets
        $this->assertNull($pr->previous_value);
    }

    /** @test */
    public function single_set_session_does_not_create_consistency_pr()
    {
        // Single set - no consistency to measure
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        
        $liftLog->liftSets()->create([
            'weight' => 0,
            'reps' => 1,
            'time' => 30,
            'notes' => '',
        ]);

        $this->triggerPRDetection($liftLog);

        // Should NOT create CONSISTENCY PR (only 1 set)
        $this->assertDatabaseMissing('personal_records', [
            'lift_log_id' => $liftLog->id,
            'pr_type' => 'consistency',
        ]);
    }

    /** @test */
    public function higher_minimum_hold_creates_consistency_pr()
    {
        // First session: 5 sets with min hold of 10s
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 0, 'reps' => 1, 'time' => 15, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 12, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 10, 'notes' => ''], // minimum
            ['weight' => 0, 'reps' => 1, 'time' => 14, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 13, 'notes' => ''],
        ]);
        $this->triggerPRDetection($firstLog);

        // Second session: 5 sets with min hold of 15s (better consistency)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 0, 'reps' => 1, 'time' => 20, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 18, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 15, 'notes' => ''], // minimum
            ['weight' => 0, 'reps' => 1, 'time' => 17, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 15, 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        // Should create CONSISTENCY PR
        $pr = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'consistency')
            ->first();

        $this->assertNotNull($pr);
        $this->assertEquals(15, $pr->value); // Min hold of 15s
        $this->assertEquals(5, $pr->rep_count); // 5 sets
        $this->assertEquals(10, $pr->previous_value); // Previous min was 10s
    }

    /** @test */
    public function same_minimum_hold_does_not_create_consistency_pr()
    {
        // First session: min hold of 15s
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 0, 'reps' => 1, 'time' => 20, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 15, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 18, 'notes' => ''],
        ]);
        $this->triggerPRDetection($firstLog);

        // Second session: same min hold of 15s (not a PR)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 0, 'reps' => 1, 'time' => 22, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 15, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 19, 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        // Should NOT create CONSISTENCY PR
        $pr = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'consistency')
            ->first();

        $this->assertNull($pr);
    }

    /** @test */
    public function lower_minimum_hold_does_not_create_consistency_pr()
    {
        // First session: min hold of 15s
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 0, 'reps' => 1, 'time' => 20, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 15, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 18, 'notes' => ''],
        ]);
        $this->triggerPRDetection($firstLog);

        // Second session: lower min hold of 12s (worse consistency)
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 0, 'reps' => 1, 'time' => 18, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 12, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 16, 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        // Should NOT create CONSISTENCY PR
        $pr = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'consistency')
            ->first();

        $this->assertNull($pr);
    }

    /** @test */
    public function consistency_pr_requires_same_or_more_sets()
    {
        // First session: 5 sets with min hold of 10s
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 0, 'reps' => 1, 'time' => 15, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 12, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 10, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 14, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 13, 'notes' => ''],
        ]);
        $this->triggerPRDetection($firstLog);

        // Second session: only 3 sets with min hold of 15s
        // Even though 15s > 10s, we only did 3 sets vs 5 sets before
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 0, 'reps' => 1, 'time' => 20, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 15, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 18, 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        // Should still create CONSISTENCY PR for 3 sets
        // (comparing to best 3-set session, not 5-set session)
        $pr = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'consistency')
            ->first();

        $this->assertNotNull($pr);
        $this->assertEquals(15, $pr->value);
        $this->assertEquals(3, $pr->rep_count);
    }

    /** @test */
    public function johns_lsit_scenario()
    {
        // Simulate John's actual scenario: "I held AT LEAST 15s across all 5 rounds"
        
        // Previous session: 5 rounds with varying times, min = 12s
        $previousLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $previousLog->liftSets()->createMany([
            ['weight' => 0, 'reps' => 1, 'time' => 15, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 13, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 12, 'notes' => ''], // minimum
            ['weight' => 0, 'reps' => 1, 'time' => 14, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 13, 'notes' => ''],
        ]);
        $this->triggerPRDetection($previousLog);

        // Today's session: 5 rounds, all at least 15s
        $todayLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $todayLog->liftSets()->createMany([
            ['weight' => 0, 'reps' => 1, 'time' => 20, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 18, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 15, 'notes' => ''], // minimum
            ['weight' => 0, 'reps' => 1, 'time' => 17, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 15, 'notes' => ''],
        ]);
        $this->triggerPRDetection($todayLog);

        // Should create CONSISTENCY PR
        $consistencyPR = PersonalRecord::where('lift_log_id', $todayLog->id)
            ->where('pr_type', 'consistency')
            ->first();

        $this->assertNotNull($consistencyPR, 'Consistency PR should be created');
        $this->assertEquals(15, $consistencyPR->value); // Min hold of 15s
        $this->assertEquals(5, $consistencyPR->rep_count); // 5 sets
        $this->assertEquals(12, $consistencyPR->previous_value); // Previous min was 12s

        // Should also create TIME PR if 20s is the longest hold ever
        $timePR = PersonalRecord::where('lift_log_id', $todayLog->id)
            ->where('pr_type', 'time')
            ->first();

        $this->assertNotNull($timePR, 'Time PR should be created');
        $this->assertEquals(20, $timePR->value);

        // Lift log should be marked as PR
        $todayLog->refresh();
        $this->assertTrue($todayLog->is_pr);
        $this->assertGreaterThanOrEqual(2, $todayLog->pr_count);
    }

    /** @test */
    public function consistency_pr_display_shows_correct_format()
    {
        // First session
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 0, 'reps' => 1, 'time' => 15, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 10, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 12, 'notes' => ''],
        ]);
        $this->triggerPRDetection($firstLog);

        // Second session with better consistency
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 0, 'reps' => 1, 'time' => 18, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 15, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 16, 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        // Get the PR and format it
        $pr = PersonalRecord::where('lift_log_id', $secondLog->id)
            ->where('pr_type', 'consistency')
            ->first();

        $config = new \App\Services\LiftLogTableRowBuilder\RowConfig();
        $assembled = \App\Services\LiftLogTableRowBuilder\PRRecordsComponentAssembler::assemble($secondLog, $config);
        $records = $assembled[0]['data']['records'] ?? [];
        $consistencyRecord = collect($records)->firstWhere('label', 'Best Min Hold');

        $this->assertNotNull($consistencyRecord);
        $this->assertEquals('10s', $consistencyRecord['value']);
        $this->assertEquals('15s', $consistencyRecord['comparison']);
    }

    /** @test */
    public function consistency_pr_can_be_achieved_alongside_other_pr_types()
    {
        // First session
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 0, 'reps' => 1, 'time' => 15, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 10, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 12, 'notes' => ''],
        ]);
        $this->triggerPRDetection($firstLog);

        // Second session: better consistency AND longer max hold
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 0, 'reps' => 1, 'time' => 25, 'notes' => ''], // New TIME PR
            ['weight' => 0, 'reps' => 1, 'time' => 20, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 18, 'notes' => ''], // New CONSISTENCY PR (min=18s)
        ]);
        $this->triggerPRDetection($secondLog);

        // Should have both CONSISTENCY and TIME PRs
        $prs = PersonalRecord::where('lift_log_id', $secondLog->id)->get();
        
        $this->assertTrue($prs->contains('pr_type', 'consistency'));
        $this->assertTrue($prs->contains('pr_type', 'time'));
    }

    /** @test */
    public function consistency_pr_shows_in_not_beaten_section_when_not_beaten()
    {
        // First session: establish consistency PR
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $firstLog->liftSets()->createMany([
            ['weight' => 0, 'reps' => 1, 'time' => 20, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 15, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 18, 'notes' => ''],
        ]);
        $this->triggerPRDetection($firstLog);

        // Second session: beat TIME PR but not CONSISTENCY PR
        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 0, 'reps' => 1, 'time' => 30, 'notes' => ''], // New TIME PR
            ['weight' => 0, 'reps' => 1, 'time' => 12, 'notes' => ''], // Worse consistency (min=12s < 15s)
            ['weight' => 0, 'reps' => 1, 'time' => 16, 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        // Should have TIME PR but not CONSISTENCY PR
        $prs = PersonalRecord::where('lift_log_id', $secondLog->id)->get();
        
        $this->assertTrue($prs->contains('pr_type', 'time'));
        $this->assertFalse($prs->contains('pr_type', 'consistency'));

        // The consistency PR from first session should still be current (not beaten)
        $currentConsistencyPR = PersonalRecord::where('exercise_id', $this->exercise->id)
            ->where('user_id', $this->user->id)
            ->where('pr_type', 'consistency')
            ->current()
            ->first();

        $this->assertNotNull($currentConsistencyPR);
        $this->assertEquals($firstLog->id, $currentConsistencyPR->lift_log_id);
        $this->assertEquals(15, $currentConsistencyPR->value); // Min hold of 15s
    }
}
