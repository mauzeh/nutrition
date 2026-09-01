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
 * Density PR PERSISTENCE + DISPLAY only.
 *
 * Density calculation (perKey count, requirePrevious first-time suppression, weight-vs-
 * duration keying, tolerance) is covered in isolation by tests/Unit/PR/PrEngineTest.php.
 * This file keeps only the assembler rendering ("Sets at {w}" label + set-count
 * formatting) and PR persistence the pure engine can't cover.
 */
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
    public function density_pr_renders_in_the_assembler_with_correct_label_and_set_counts(): void
    {
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $firstLog->liftSets()->create(['weight' => 145, 'reps' => 10, 'notes' => '']);
        $this->triggerPRDetection($firstLog);

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

        $assembled = PRRecordsComponentAssembler::assemble($secondLog, new RowConfig());
        $records = $assembled[0]['data']['records'] ?? [];
        $record = collect($records)->firstWhere('label', 'Sets at 145');

        $this->assertNotNull($record);
        $this->assertEquals('1 set', $record['value']);
        $this->assertEquals('2 sets', $record['comparison']);
    }

    /** @test */
    public function density_pr_persists_as_a_personal_record(): void
    {
        $firstLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $firstLog->liftSets()->create(['weight' => 145, 'reps' => 10, 'notes' => '']);
        $this->triggerPRDetection($firstLog);

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

        $prs = PersonalRecord::where('lift_log_id', $secondLog->id)->get();
        $this->assertTrue($prs->contains('pr_type', 'density'));
    }
}
