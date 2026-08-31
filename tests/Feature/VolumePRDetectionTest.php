<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WIRING smoke test only.
 *
 * PR calculation logic (volume math, 1RM+volume co-detection, first-lift, tolerances)
 * is covered in isolation by tests/Unit/PR/PrEngineTest.php. This test exists solely to
 * prove the HTTP store path threads detected PR types into `session('is_pr')` and the
 * success message — the controller wiring, not the calculation.
 */
class VolumePRDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    protected User $user;

    /** @test */
    public function detected_pr_types_are_surfaced_to_the_session_on_store(): void
    {
        $exercise = Exercise::factory()->create(['user_id' => $this->user->id]);

        // Prior session so the new log has something to beat.
        $prior = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $prior->liftSets()->createMany([
            ['weight' => 100, 'reps' => 5],
            ['weight' => 100, 'reps' => 5],
        ]);

        $this->post(route('lift-logs.store'), [
            'exercise_id' => $exercise->id,
            'weight' => 120,
            'reps' => 5,
            'rounds' => 3,
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ]);

        // The path wired detected types through to the session and messaged the PR.
        $prTypes = session('is_pr');
        $this->assertNotEmpty($prTypes);
        $this->assertContains('volume', $prTypes);
        $this->assertContains('one_rm', $prTypes);
        $this->assertStringContainsString('PR!', session('success'));
    }
}
