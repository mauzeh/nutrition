<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\TriggersPRDetection;
use Tests\TestCase;

/**
 * Cardio PR PERSISTENCE + DISPLAY only.
 *
 * Cardio PR calculation (endurance/volume/rep_specific math, distance normalization,
 * mutual non-interference, no 1RM) is covered in isolation by tests/Unit/PR/PrEngineTest.php
 * (cardio family). This file keeps only what the pure engine can't cover: that detected PRs
 * persist as personal_records rows with the right pr_type strings + flags, and that the
 * cardio strategy renders distance in the display.
 */
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
    public function cardio_display_shows_distance_not_weight(): void
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

        $display = $this->exercise->getTypeStrategy()->formatWeightDisplay($liftLog);
        $this->assertEquals('500m', $display);
    }

    /** @test */
    public function detected_cardio_prs_persist_with_correct_types_and_flags(): void
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
        $liftLog->refresh();

        $this->assertTrue($liftLog->is_pr);
        $prs = PersonalRecord::where('lift_log_id', $liftLog->id)->get();
        $this->assertTrue($prs->contains('pr_type', 'endurance'));
        $this->assertTrue($prs->contains('pr_type', 'volume'));
        $this->assertTrue($prs->contains('pr_type', 'rep_specific'));
        $this->assertFalse($prs->contains('pr_type', 'one_rm'));
    }
}
