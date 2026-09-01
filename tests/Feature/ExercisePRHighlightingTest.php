<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Helpers\TriggersPRDetection;
use App\Models\User;
use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\LiftSet;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ExercisePRHighlightingTest extends TestCase
{
    use RefreshDatabase, TriggersPRDetection;

    /** @test */
    public function it_displays_pr_badge_and_styling_for_pr_logs()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'exercise_type' => 'regular',
            'user_id' => $user->id
        ]);

        // Create a non-PR log (older, lighter)
        $nonPrLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDays(2)
        ]);
        LiftSet::factory()->create([
            'lift_log_id' => $nonPrLog->id,
            'weight' => 250,
            'reps' => 1
        ]);
        $this->triggerPRDetection($nonPrLog);

        // Create a PR log (newer, heavier)
        $prLog = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDays(1)
        ]);
        LiftSet::factory()->create([
            'lift_log_id' => $prLog->id,
            'weight' => 275,
            'reps' => 1
        ]);
        $this->triggerPRDetection($prLog);

        $response = $this->actingAs($user)
            ->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        
        // Check for PR badge
        $response->assertSee('🏆 PR', false);
        
        // Check for PR row CSS class
        $response->assertSee('row-pr', false);
    }

    /** @test */
    public function it_displays_multiple_pr_badges_for_different_rep_ranges()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'exercise_type' => 'regular',
            'user_id' => $user->id
        ]);

        // 275 lbs × 1 rep = 275 lbs estimated 1RM (PR at the time - first lift)
        $log1 = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDays(3)
        ]);
        LiftSet::factory()->create([
            'lift_log_id' => $log1->id,
            'weight' => 275,
            'reps' => 1
        ]);
        $this->triggerPRDetection($log1);

        // 260 lbs × 2 reps = 277.3 lbs estimated 1RM (PR at the time - beats log1)
        $log2 = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDays(2)
        ]);
        LiftSet::factory()->create([
            'lift_log_id' => $log2->id,
            'weight' => 260,
            'reps' => 2
        ]);
        $this->triggerPRDetection($log2);

        // 255 lbs × 3 reps = 280.5 lbs estimated 1RM (PR at the time - beats log2)
        $log3 = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDays(1)
        ]);
        LiftSet::factory()->create([
            'lift_log_id' => $log3->id,
            'weight' => 255,
            'reps' => 3
        ]);
        $this->triggerPRDetection($log3);

        $response = $this->actingAs($user)
            ->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        
        // Should see 3 PR badges (all three were PRs at the time they were achieved)
        $content = $response->getContent();
        $prBadgeCount = substr_count($content, '🏆 PR');
        $this->assertEquals(3, $prBadgeCount, 'Expected 3 PR badges - each lift was a PR when it happened');
    }

    /** @test */
    public function it_displays_pr_for_bodyweight_exercises_with_volume_pr()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'exercise_type' => 'bodyweight',
            'user_id' => $user->id
        ]);

        $log = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id
        ]);
        LiftSet::factory()->create([
            'lift_log_id' => $log->id,
            'weight' => 0,
            'reps' => 20
        ]);
        
        // Trigger PR detection
        event(new \App\Events\LiftLogCompleted($log));

        $response = $this->actingAs($user)
            ->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        
        // Bodyweight exercises support Volume PRs (using total reps)
        $response->assertSee('🏆 PR');
    }

    /** @test */
    public function it_does_not_display_pr_for_band_exercises()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'exercise_type' => 'banded',
            'user_id' => $user->id
        ]);

        $log = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id
        ]);
        LiftSet::factory()->create([
            'lift_log_id' => $log->id,
            'weight' => 0,
            'reps' => 10,
            'band_color' => 'red'
        ]);

        $response = $this->actingAs($user)
            ->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        
        // Should not see PR badge
        $response->assertDontSee('🏆 PR', false);
    }

    /** @test */
    public function it_only_marks_pr_for_1_2_3_rep_ranges()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'exercise_type' => 'regular',
            'user_id' => $user->id
        ]);

        // 300 lbs × 5 reps = 349.95 lbs estimated 1RM (PR at the time - first lift)
        $log1 = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDays(2)
        ]);
        LiftSet::factory()->create([
            'lift_log_id' => $log1->id,
            'weight' => 300,
            'reps' => 5
        ]);
        $this->triggerPRDetection($log1);

        // 250 lbs × 1 rep = 250 lbs estimated 1RM (NOT a PR - doesn't beat log1)
        $log2 = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDays(1)
        ]);
        LiftSet::factory()->create([
            'lift_log_id' => $log2->id,
            'weight' => 250,
            'reps' => 1
        ]);
        $this->triggerPRDetection($log2);

        $response = $this->actingAs($user)
            ->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);

        // Should see 1 PR badge:
        // - The 5 rep lift (highest estimated 1RM + its rep-specific best)
        // The 1 rep lift is NOT a PR: 250×1 is DOMINATED by the earlier 300×5 (a heavier
        // weight for more reps already stands), so the frozen cross-app PR spec's
        // suppressDominated rule for the weightlifting family suppresses it. This matches the
        // Athlete engine and the `dominated-rep-not-pr` contract fixture — the two apps agree
        // a dominated single is not a personal record.
        $content = $response->getContent();
        $prBadgeCount = substr_count($content, '🏆 PR');
        $this->assertEquals(1, $prBadgeCount, 'Expected 1 PR badge - the dominated 250×1 single is not a PR (suppressDominated)');
    }

    /** @test */
    public function it_displays_pr_cards_and_calculator_with_pr_data()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'exercise_type' => 'regular',
            'user_id' => $user->id
        ]);

        // Create PRs for all three rep ranges
        foreach ([1 => 275, 2 => 260, 3 => 255] as $reps => $weight) {
            $log = LiftLog::factory()->create([
                'user_id' => $user->id,
                'exercise_id' => $exercise->id
            ]);
            LiftSet::factory()->create([
                'lift_log_id' => $log->id,
                'weight' => $weight,
                'reps' => $reps
            ]);
        }

        $response = $this->actingAs($user)
            ->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        
        // Should see PR cards section
        $response->assertSee('Heaviest Lifts');
        
        // Should see calculator grid
        $response->assertSee('1-Rep Max Percentages');
        
        // Should see the PR values in cards
        $response->assertSee('275');
        $response->assertSee('260');
        $response->assertSee('255');
    }

    /** @test */
    public function it_handles_tied_prs_correctly()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'exercise_type' => 'regular',
            'user_id' => $user->id
        ]);

        // Two logs with same weight for 1 rep
        $log1 = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDays(2)
        ]);
        LiftSet::factory()->create([
            'lift_log_id' => $log1->id,
            'weight' => 275,
            'reps' => 1
        ]);
        $this->triggerPRDetection($log1);

        $log2 = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDays(1)
        ]);
        LiftSet::factory()->create([
            'lift_log_id' => $log2->id,
            'weight' => 275,
            'reps' => 1
        ]);
        $this->triggerPRDetection($log2);

        $response = $this->actingAs($user)
            ->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        
        // Only the first should be marked as PR (ties don't count as new PRs)
        $content = $response->getContent();
        $prBadgeCount = substr_count($content, '🏆 PR');
        $this->assertEquals(1, $prBadgeCount, 'Expected 1 PR badge - only the first lift, ties do not count as new PRs');
    }

    /** @test */
    public function it_displays_pr_styling_with_orange_theme()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'exercise_type' => 'regular',
            'user_id' => $user->id
        ]);

        $log = LiftLog::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id
        ]);
        LiftSet::factory()->create([
            'lift_log_id' => $log->id,
            'weight' => 275,
            'reps' => 1
        ]);
        $this->triggerPRDetection($log);

        $response = $this->actingAs($user)
            ->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        
        // Check that CSS file with PR styling is loaded
        $response->assertSee('table.css');
        
        // Verify PR badge class exists
        $response->assertSee('table-badge--pr', false);
    }

    /** @test */
    public function pr_highlighting_does_not_affect_page_performance_significantly()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'exercise_type' => 'regular',
            'user_id' => $user->id
        ]);

        // Create 50 lift logs to test performance
        for ($i = 0; $i < 50; $i++) {
            $log = LiftLog::factory()->create([
                'user_id' => $user->id,
                'exercise_id' => $exercise->id,
                'logged_at' => now()->subDays($i)
            ]);
            LiftSet::factory()->create([
                'lift_log_id' => $log->id,
                'weight' => 200 + $i,
                'reps' => 1
            ]);
        }

        $startTime = microtime(true);
        
        $response = $this->actingAs($user)
            ->get(route('exercises.show-logs', $exercise));

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        $response->assertStatus(200);
        
        // Should complete in reasonable time (less than 1 second)
        $this->assertLessThan(1.0, $executionTime, 
            "Page load took {$executionTime}s, which is too slow");
    }
}
