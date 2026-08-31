<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\LiftLogTableRowBuilder\PRRecordsComponentAssembler;
use App\Services\LiftLogTableRowBuilder\RowConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\TriggersPRDetection;
use Tests\TestCase;

/**
 * Consistency PR PERSISTENCE + DISPLAY only.
 *
 * Consistency calculation (minOf, minGroupSize>1, co-detection with time) is covered in
 * isolation by tests/Unit/PR/PrEngineTest.php (static_hold family). This file keeps only the
 * display-assembler rendering ("Best Min Hold" label + values) and the beaten/current
 * persistence behavior the pure engine can't cover.
 */
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
    public function consistency_pr_renders_in_the_assembler_with_correct_label_and_values(): void
    {
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

        $assembled = PRRecordsComponentAssembler::assemble($secondLog, new RowConfig());
        $records = $assembled[0]['data']['records'] ?? [];
        $record = collect($records)->firstWhere('label', 'Best Min Hold');

        $this->assertNotNull($record);
        $this->assertEquals('10s', $record['value']);
        $this->assertEquals('15s', $record['comparison']);
    }

    /** @test */
    public function an_unbeaten_consistency_pr_stays_current_when_only_time_is_beaten(): void
    {
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

        $secondLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 0, 'reps' => 1, 'time' => 30, 'notes' => ''], // new TIME PR
            ['weight' => 0, 'reps' => 1, 'time' => 12, 'notes' => ''], // worse min => no consistency PR
            ['weight' => 0, 'reps' => 1, 'time' => 16, 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        $prs = PersonalRecord::where('lift_log_id', $secondLog->id)->get();
        $this->assertTrue($prs->contains('pr_type', 'time'));
        $this->assertFalse($prs->contains('pr_type', 'consistency'));

        $currentConsistency = PersonalRecord::where('exercise_id', $this->exercise->id)
            ->where('user_id', $this->user->id)
            ->where('pr_type', 'consistency')
            ->current()
            ->first();
        $this->assertNotNull($currentConsistency);
        $this->assertEquals($firstLog->id, $currentConsistency->lift_log_id);
    }
}
