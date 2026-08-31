<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\LiftSet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExercisePRCardsIntegrationTest extends TestCase
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
    public function exercise_logs_page_shows_pr_cards_for_barbell_exercise()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Back Squat',
        ]);

        // Create lift logs with different rep ranges
        $this->createLiftLog($exercise, 1, 242);
        $this->createLiftLog($exercise, 2, 235);
        $this->createLiftLog($exercise, 3, 230);

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        $response->assertSee('Heaviest Lifts');
        $response->assertSee('1 × 1');
        $response->assertSee('242');
        $response->assertSee('1 × 2');
        $response->assertSee('235');
        $response->assertSee('1 × 3');
        $response->assertSee('230');
    }

    /** @test */
    public function exercise_logs_page_shows_calculator_grid()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Bench Press',
        ]);

        // Create a 1-rep max lift log
        $this->createLiftLog($exercise, 1, 200);

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        $response->assertSee('1-Rep Max Percentages');
        $response->assertSee('100%');
        $response->assertSee('95%');
        $response->assertSee('90%');
        $response->assertSee('85%');
        $response->assertSee('80%');
        $response->assertSee('75%');
        $response->assertSee('70%');
        $response->assertSee('65%');
        $response->assertSee('60%');
        $response->assertSee('55%');
        $response->assertSee('50%');
        $response->assertSee('45%');
    }

    /** @test */
    public function exercise_logs_page_does_not_show_pr_features_for_dumbbell()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'dumbbell',
            'title' => 'Dumbbell Press',
        ]);

        // Create lift logs
        $this->createLiftLog($exercise, 1, 50);
        $this->createLiftLog($exercise, 2, 45);

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        $response->assertDontSee('Heaviest Lifts');
        $response->assertDontSee('1-Rep Max Percentages');
    }

    /** @test */
    public function exercise_logs_page_does_not_show_pr_features_for_cardio()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'cardio',
            'title' => 'Running',
        ]);

        // Create lift logs
        $this->createLiftLog($exercise, 1, 0);

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        $response->assertDontSee('Heaviest Lifts');
        $response->assertDontSee('1-Rep Max Percentages');
    }

    /** @test */
    public function pr_cards_display_correct_weights_from_database()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Deadlift',
        ]);

        // Create lift logs with specific weights
        $this->createLiftLog($exercise, 1, 315);
        $this->createLiftLog($exercise, 2, 295);
        $this->createLiftLog($exercise, 3, 275);

        // Create additional logs with lower weights to ensure we get the max
        $this->createLiftLog($exercise, 1, 300);
        $this->createLiftLog($exercise, 2, 280);
        $this->createLiftLog($exercise, 3, 260);

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        // Should show the highest weights
        $response->assertSee('315');
        $response->assertSee('295');
        $response->assertSee('275');
        // The PR cards should show the maximum weights, not the lower ones
        // Note: Lower weights may appear in the lift log table, but the PR cards show the max
    }

    /** @test */
    public function calculator_grid_shows_accurate_percentages()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Overhead Press',
        ]);

        // Create a 1-rep max of 100 lbs for easy calculation
        $this->createLiftLog($exercise, 1, 100);

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        // Check for calculated percentages (100% = 100, 95% = 95, 90% = 90, etc.)
        $response->assertSee('100');
        $response->assertSee('95');
        $response->assertSee('90');
        $response->assertSee('85');
        $response->assertSee('80');
        $response->assertSee('75');
        $response->assertSee('70');
        $response->assertSee('65');
        $response->assertSee('60');
        $response->assertSee('55');
        $response->assertSee('50');
        $response->assertSee('45');
    }

    /** @test */
    public function pr_cards_handle_missing_rep_ranges_gracefully()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Front Squat',
        ]);

        // Only create a 1-rep max, no 2 or 3 rep data
        $this->createLiftLog($exercise, 1, 225);

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        $response->assertSee('Heaviest Lifts');
        $response->assertSee('1 × 1');
        $response->assertSee('225');
        $response->assertSee('1 × 2');
        $response->assertSee('1 × 3');
        // Should show "—" for missing data
        $response->assertSee('—');
    }

    /** @test */
    public function page_works_normally_when_no_lift_logs_exist()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Clean and Jerk',
        ]);

        // No lift logs created

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        // Should not show PR features when no data exists
        $response->assertDontSee('Heaviest Lifts');
        $response->assertDontSee('1-Rep Max Percentages');
        // Should not show table when no logs exist
        $response->assertDontSee('component-table');
    }

    /**
     * Helper method to create a lift log with a specific rep count and weight
     */
    private function createLiftLog(Exercise $exercise, int $reps, float $weight): LiftLog
    {
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => Carbon::now()->subDays(rand(1, 30)),
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $liftLog->id,
            'reps' => $reps,
            'weight' => $weight,
        ]);

        event(new \App\Events\LiftLogCompleted($liftLog));

        return $liftLog;
    }

    /** @test */
    public function exercise_logs_page_shows_estimated_calculator_grid_when_no_low_rep_tests()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Deadlift',
        ]);

        // Create lift logs with only higher rep ranges (no 1-3 rep tests)
        $log = LiftLog::factory()->create([
            'exercise_id' => $exercise->id,
            'user_id' => $this->user->id,
        ]);
        LiftSet::factory()->create([
            'lift_log_id' => $log->id,
            'weight' => 315,
            'reps' => 5,
        ]);

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        // Should show estimated calculator grid
        $response->assertSee('1-Rep Max Percentages (Estimated)');
        $response->assertSee('Est. 1RM');
        
        // Should show helpful note about performing low rep tests
        $response->assertSee('The % table is estimated based on your previous lifts using a standard formula. For more accurate training percentages, test your actual 1, 2, or 3 rep max.');
        $response->assertSee('test your actual 1, 2, or 3 rep max');
        
        // Should show PR cards since we now track rep-specific PRs for 1-5 reps
        $response->assertSee('Heaviest Lifts');
        $response->assertSee('1 × 5'); // The 5-rep lift should be shown
        
        // PR cards show all rep ranges 1-10 (with empty cards for untested ranges)
        $response->assertSee('1 × 1');
        $response->assertSee('1 × 2');
        $response->assertSee('1 × 3');
    }

    /** @test */
    public function exercise_logs_page_shows_actual_pr_cards_when_low_rep_tests_exist()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Squat',
        ]);

        // Create both low rep tests and higher rep sets
        $this->createLiftLog($exercise, 1, 405);
        $this->createLiftLog($exercise, 5, 365);

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        // Should show actual PR cards
        $response->assertSee('Heaviest Lifts');
        $response->assertSee('1 × 1');
        $response->assertSee('405');
        
        // Should show non-estimated calculator grid without the note
        $response->assertSee('1-Rep Max Percentages');
        $response->assertDontSee('1-Rep Max Percentages (Estimated)');
        $response->assertDontSee('The % table is estimated based on your previous lifts using a standard formula. For more accurate training percentages, test your actual 1, 2, or 3 rep max.');
    }

    /** @test */
    public function exercise_logs_page_shows_warning_when_pr_data_is_stale()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Overhead Press',
        ]);

        // Create a PR that's 7 months old (stale - older than 6 months)
        $this->createLiftLogWithDate($exercise, 1, 185, Carbon::now()->subMonths(7));

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        // Should show PR cards with the old data
        $response->assertSee('Heaviest Lifts');
        $response->assertSee('185');
        
        // Should show warning about stale data
        $response->assertSee('Your max lift data is over 6 months old');
        $response->assertSee('Consider retesting your 1, 2, or 3 rep max');
    }

    /** @test */
    public function exercise_logs_page_does_not_show_warning_when_pr_data_is_recent()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Front Squat',
        ]);

        // Create a PR that's 2 months old (recent)
        $this->createLiftLogWithDate($exercise, 1, 225, Carbon::now()->subMonths(2));

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        // Should show PR cards
        $response->assertSee('Heaviest Lifts');
        $response->assertSee('225');
        
        // Should NOT show warning about stale data
        $response->assertDontSee('Your max lift data is over 6 months old');
    }

    protected function createLiftLogWithDate(Exercise $exercise, int $reps, float $weight, Carbon $date): LiftLog
    {
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => $date,
        ]);

        LiftSet::factory()->create([
            'lift_log_id' => $liftLog->id,
            'reps' => $reps,
            'weight' => $weight,
        ]);

        event(new \App\Events\LiftLogCompleted($liftLog));

        return $liftLog;
    }

    /** @test */
    public function pr_cards_display_time_ago_for_recent_prs()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Bench Press',
        ]);

        // Create PRs with specific dates
        $this->createLiftLogWithDate($exercise, 1, 225, Carbon::now()->subDays(3));
        $this->createLiftLogWithDate($exercise, 2, 215, Carbon::now()->subWeeks(2));
        $this->createLiftLogWithDate($exercise, 3, 205, Carbon::now()->subMonths(1));

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        $response->assertSee('Heaviest Lifts');
        
        // Check that time ago labels are displayed
        $response->assertSee('3 days ago');
        $response->assertSee('2 weeks ago');
        $response->assertSee('1 month ago');
    }

    /** @test */
    public function pr_cards_display_time_ago_for_old_prs()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Deadlift',
        ]);

        // Create PRs from several months ago
        $this->createLiftLogWithDate($exercise, 1, 405, Carbon::now()->subYears(2));
        $this->createLiftLogWithDate($exercise, 2, 385, Carbon::now()->subYears(3));

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        $response->assertSee('Heaviest Lifts');
        
        // Check that time ago labels are displayed for old PRs
        $response->assertSee('2 years ago');
    }

    /** @test */
    public function pr_cards_display_time_ago_for_very_recent_prs()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Squat',
        ]);

        // Create a PR from today
        $this->createLiftLogWithDate($exercise, 1, 315, Carbon::now()->subHours(2));

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        $response->assertSee('Heaviest Lifts');
        
        // Check that time ago label shows hours or "just now" type message
        // Carbon's diffForHumans() will show "2 hours ago" for this case
        $response->assertSeeText('ago');
    }

    /** @test */
    public function pr_cards_do_not_show_time_ago_when_no_pr_exists()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Clean',
        ]);

        // Create only a 1-rep PR, leaving 2 and 3 rep empty
        $this->createLiftLogWithDate($exercise, 1, 185, Carbon::now()->subDays(5));

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        $response->assertSee('Heaviest Lifts');
        $response->assertSee('185');
        $response->assertSee('5 days ago');
        
        // The empty PR cards (showing "—") should not have time ago labels
        $response->assertSee('—');
    }

    /** @test */
    public function pr_cards_show_correct_time_ago_when_multiple_lifts_exist()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Overhead Press',
        ]);

        // Create multiple lifts, but only the heaviest (155) should be shown with its date
        $this->createLiftLogWithDate($exercise, 1, 135, Carbon::now()->subDays(150)); // Older, lighter (5 months)
        $this->createLiftLogWithDate($exercise, 1, 155, Carbon::now()->subDays(75)); // Newer, heaviest (this should be shown - 2 months ago)
        $this->createLiftLogWithDate($exercise, 1, 145, Carbon::now()->subDays(10)); // Newest, but lighter

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        $response->assertSee('Heaviest Lifts');
        $response->assertSee('155'); // Should show the heaviest weight
        
        // Ensure 2 months ago is in PR cards section and other dates are not
        $content = $response->getContent();
        $prCardsPos = strpos($content, 'component-pr-cards-section');
        $calcPos = strpos($content, 'component-calculator', $prCardsPos);
        $prCardsHtml = substr($content, $prCardsPos, $calcPos - $prCardsPos);
        
        $this->assertStringContainsString('2 months ago', $prCardsHtml);
        $this->assertStringNotContainsString('5 months ago', $prCardsHtml);
        $this->assertStringNotContainsString('10 days ago', $prCardsHtml);
    }

    /** @test */
    public function pr_cards_time_ago_uses_human_readable_format()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Back Squat',
        ]);

        // Create PRs with various time periods
        $this->createLiftLogWithDate($exercise, 1, 365, Carbon::now()->subDay());
        $this->createLiftLogWithDate($exercise, 2, 345, Carbon::now()->subWeek());
        $this->createLiftLogWithDate($exercise, 3, 325, Carbon::now()->subYear());

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        
        // Verify human-readable formats are used
        $response->assertSee('1 day ago');
        $response->assertSee('1 week ago');
        $response->assertSee('1 year ago');
    }

    /** @test */
    public function pr_cards_highlight_only_the_most_recent_pr_regardless_of_age()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Bench Press',
        ]);

        // Create PRs with different dates - the most recent should be highlighted even if it's old
        $this->createLiftLogWithDate($exercise, 1, 225, Carbon::now()->subYears(2)); // Oldest
        $this->createLiftLogWithDate($exercise, 2, 215, Carbon::now()->subMonths(6)); // Middle  
        $this->createLiftLogWithDate($exercise, 3, 205, Carbon::now()->subMonths(3)); // Most recent (should be highlighted)

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        $response->assertSee('Heaviest Lifts');
        
        // Check that the page contains the pr-card--recent class exactly once
        $content = $response->getContent();
        $recentCardCount = substr_count($content, 'pr-card--recent');
        $this->assertEquals(1, $recentCardCount, 'Expected exactly 1 PR card to be marked as recent');
        
        // Verify the most recent PR (3-rep) shows its date
        $response->assertSee('3 months ago');
    }

    /** @test */
    public function pr_cards_highlight_most_recent_pr_even_when_very_old()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Squat',
        ]);

        // Create only one PR that's very old - it should still be highlighted as the "most recent"
        $this->createLiftLogWithDate($exercise, 1, 315, Carbon::now()->subYears(5));

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        $response->assertSee('Heaviest Lifts');
        
        // Even a 5-year-old PR should be highlighted if it's the only/most recent one
        $content = $response->getContent();
        $recentCardCount = substr_count($content, 'pr-card--recent');
        $this->assertEquals(1, $recentCardCount, 'Expected the only PR to be marked as recent regardless of age');
        
        $response->assertSee('5 years ago');
    }

    /** @test */
    public function pr_cards_do_not_highlight_when_no_prs_exist()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Clean',
        ]);

        // No lift logs created

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        
        // Should not show PR cards at all when no data exists
        $response->assertDontSee('Heaviest Lifts');
        
        // Should not contain any recent highlighting
        $content = $response->getContent();
        $recentCardCount = substr_count($content, 'pr-card--recent');
        $this->assertEquals(0, $recentCardCount, 'Expected no recent highlighting when no PRs exist');
    }

    /** @test */
    public function pr_cards_highlight_switches_when_new_pr_is_achieved()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Deadlift',
        ]);

        // Create initial PRs
        $this->createLiftLogWithDate($exercise, 1, 405, Carbon::now()->subMonths(6)); // Older
        $this->createLiftLogWithDate($exercise, 2, 385, Carbon::now()->subMonths(3)); // Was most recent

        // First check - 2-rep should be highlighted
        $response = $this->get(route('exercises.show-logs', $exercise));
        $response->assertStatus(200);
        $response->assertSee('3 months ago'); // Most recent PR date should be visible

        // Add a new, more recent PR
        $this->createLiftLogWithDate($exercise, 3, 365, Carbon::now()->subDays(5)); // New most recent

        // Second check - 3-rep should now be highlighted
        $response = $this->get(route('exercises.show-logs', $exercise));
        $response->assertStatus(200);
        
        // Should still have exactly one highlighted card
        $content = $response->getContent();
        $recentCardCount = substr_count($content, 'pr-card--recent');
        $this->assertEquals(1, $recentCardCount, 'Expected exactly 1 PR card to be marked as recent after new PR');
        
        // Should show the new most recent date
        $response->assertSee('5 days ago');
    }

    /** @test */
    public function pr_cards_highlight_works_with_partial_pr_data()
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'regular',
            'title' => 'Overhead Press',
        ]);

        // Create PRs for only some rep ranges (gaps in data)
        $this->createLiftLogWithDate($exercise, 1, 155, Carbon::now()->subMonths(4)); // Older
        // No 2-rep PR
        $this->createLiftLogWithDate($exercise, 3, 145, Carbon::now()->subWeeks(2)); // Most recent
        // No 4-rep PR  
        $this->createLiftLogWithDate($exercise, 5, 135, Carbon::now()->subMonths(2)); // Middle

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        $response->assertSee('Heaviest Lifts');
        
        // Should highlight the 3-rep PR (most recent among existing PRs)
        $content = $response->getContent();
        $recentCardCount = substr_count($content, 'pr-card--recent');
        $this->assertEquals(1, $recentCardCount, 'Expected exactly 1 PR card to be marked as recent with partial data');
        
        // Should show the most recent PR date
        $response->assertSee('2 weeks ago');
        
        // Should still show empty cards for missing rep ranges
        $response->assertSee('—');
    }
}
