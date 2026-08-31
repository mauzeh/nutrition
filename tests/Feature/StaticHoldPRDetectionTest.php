<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\LiftSet;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\TriggersPRDetection;
use Tests\TestCase;

/**
 * Static-hold PR DISPLAY + PERSISTENCE only.
 *
 * Static-hold PR calculation (time/consistency/volume/density math, minGroupSize, duration
 * keying, no 1RM/hypertrophy) is covered in isolation by tests/Unit/PR/PrEngineTest.php
 * (static_hold family). This file keeps only what the pure engine can't: the blade/route
 * duration rendering ("45s hold", "1m hold", "Last workout") and density PR persistence.
 */
class StaticHoldPRDetectionTest extends TestCase
{
    use RefreshDatabase, TriggersPRDetection;

    /** @test */
    public function display_shows_the_hold_duration_not_the_reps_field(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'L-sit',
        ]);
        $liftLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);
        LiftSet::factory()->create(['lift_log_id' => $liftLog->id, 'weight' => 0, 'reps' => 1, 'time' => 45]);

        $response = $this->actingAs($user)->get(route('mobile-entry.lifts'));

        $response->assertStatus(200);
        $response->assertSee('45s hold');
        $response->assertDontSee('1s hold');
        $response->assertSee('L-sit');
    }

    /** @test */
    public function display_shows_duration_with_added_weight(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'Weighted Plank',
        ]);
        $liftLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);
        LiftSet::factory()->create(['lift_log_id' => $liftLog->id, 'weight' => 25, 'reps' => 1, 'time' => 60]);

        $response = $this->actingAs($user)->get(route('mobile-entry.lifts'));

        $response->assertStatus(200);
        $response->assertSee('1m hold');
        $response->assertSee('+25 lbs');
        $response->assertDontSee('1s hold');
        $response->assertSee('Weighted Plank');
    }

    /** @test */
    public function last_workout_message_shows_the_hold_duration(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'L-sit',
        ]);
        $previousLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        LiftSet::factory()->create(['lift_log_id' => $previousLog->id, 'weight' => 0, 'reps' => 1, 'time' => 45]);

        $response = $this->actingAs($user)->get(route('lift-logs.create', [
            'exercise_id' => $exercise->id,
            'date' => now()->toDateString(),
        ]));

        $response->assertStatus(200);
        $response->assertSee('Last workout');
        $response->assertSee('45s hold');
        $response->assertDontSee('0s hold');
        $response->assertDontSee('1s hold');
    }

    /** @test */
    public function last_workout_message_shows_duration_and_weight(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'Weighted Plank',
        ]);
        $previousLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        LiftSet::factory()->create(['lift_log_id' => $previousLog->id, 'weight' => 25, 'reps' => 1, 'time' => 90]);

        $response = $this->actingAs($user)->get(route('lift-logs.create', [
            'exercise_id' => $exercise->id,
            'date' => now()->toDateString(),
        ]));

        $response->assertStatus(200);
        $response->assertSee('Last workout');
        $response->assertSee('1m 30s hold');
        $response->assertSee('+25 lbs');
    }

    /** @test */
    public function density_pr_persists_for_static_holds(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'user_id' => $user->id,
            'exercise_type' => 'static_hold',
            'title' => 'L-sit',
        ]);

        $firstLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $firstLog->liftSets()->create(['weight' => 0, 'reps' => 1, 'time' => 30, 'notes' => '']);
        $this->triggerPRDetection($firstLog);

        $secondLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);
        $secondLog->liftSets()->createMany([
            ['weight' => 0, 'reps' => 1, 'time' => 30, 'notes' => ''],
            ['weight' => 0, 'reps' => 1, 'time' => 30, 'notes' => ''],
        ]);
        $this->triggerPRDetection($secondLog);

        $prs = PersonalRecord::where('lift_log_id', $secondLog->id)->get();
        $this->assertTrue($prs->contains('pr_type', 'density'));
    }
}
