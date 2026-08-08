<?php

namespace Tests\Feature\MobileEntry;

use Tests\TestCase;
use App\Models\User;
use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\LiftSet;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Feature test for notes display in mobile-entry lifts page.
 * 
 * Tests that user notes are properly displayed in the lift log table,
 * including the "N/A" fallback for empty notes and proper CSS styling.
 */
class NotesDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Exercise $exercise;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->exercise = Exercise::factory()->create([
            'title' => 'Bench Press',
            'exercise_type' => 'weighted'
        ]);
    }

    /** @test */
    public function it_displays_notes_when_present()
    {
        // Arrange: Create a lift log with notes
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'comments' => 'Felt really strong today! New PR incoming.',
            'logged_at' => now()
        ]);
        
        LiftSet::factory()->create([
            'lift_log_id' => $liftLog->id,
            'weight' => 185,
            'reps' => 5
        ]);

        // Act: Visit the mobile-entry lifts page
        $response = $this->actingAs($this->user)->get(route('mobile-entry.lifts'));

        // Assert: Page loads successfully and shows notes
        $response->assertStatus(200);
        $response->assertSee('Your notes:', false);
        $response->assertSee('Felt really strong today! New PR incoming.', false);
        
        // Assert: Notes are in a neutral message component
        $response->assertSee('component-message--neutral', false);
    }

    /** @test */
    public function it_displays_na_when_notes_are_null()
    {
        // Arrange: Create a lift log without notes
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'comments' => null,
            'logged_at' => now()
        ]);
        
        LiftSet::factory()->create([
            'lift_log_id' => $liftLog->id,
            'weight' => 135,
            'reps' => 8
        ]);

        // Act: Visit the mobile-entry lifts page
        $response = $this->actingAs($this->user)->get(route('mobile-entry.lifts'));

        // Assert: Page loads successfully and shows N/A for notes
        $response->assertStatus(200);
        $response->assertSee('Your notes:', false);
        $response->assertSee('N/A', false);
        
        // Assert: Notes section still appears with neutral styling
        $response->assertSee('component-message--neutral', false);
    }

    /** @test */
    public function it_displays_na_when_notes_are_empty_string()
    {
        // Arrange: Create a lift log with empty string notes
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'comments' => '',
            'logged_at' => now()
        ]);
        
        LiftSet::factory()->create([
            'lift_log_id' => $liftLog->id,
            'weight' => 225,
            'reps' => 3
        ]);

        // Act: Visit the mobile-entry lifts page
        $response = $this->actingAs($this->user)->get(route('mobile-entry.lifts'));

        // Assert: Page loads successfully and shows N/A for empty notes
        $response->assertStatus(200);
        $response->assertSee('Your notes:', false);
        $response->assertSee('N/A', false);
    }

    /** @test */
    public function it_displays_na_when_notes_are_whitespace_only()
    {
        // Arrange: Create a lift log with whitespace-only notes
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'comments' => '   ',
            'logged_at' => now()
        ]);
        
        LiftSet::factory()->create([
            'lift_log_id' => $liftLog->id,
            'weight' => 155,
            'reps' => 10
        ]);

        // Act: Visit the mobile-entry lifts page
        $response = $this->actingAs($this->user)->get(route('mobile-entry.lifts'));

        // Assert: Page loads successfully and shows N/A for whitespace notes
        $response->assertStatus(200);
        $response->assertSee('Your notes:', false);
        $response->assertSee('N/A', false);
    }

    /** @test */
    public function it_displays_multiple_lift_logs_with_mixed_notes()
    {
        // Arrange: Create multiple lift logs with different note scenarios
        // Use today() + hours to guarantee both logs land on the same calendar date
        // regardless of what time of day the test runs (avoids midnight boundary issues)
        $liftLog1 = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'comments' => 'Great workout!',
            'logged_at' => today()->addHours(10)
        ]);
        
        $liftLog2 = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'comments' => null,
            'logged_at' => today()->addHours(11)
        ]);
        
        LiftSet::factory()->create(['lift_log_id' => $liftLog1->id, 'weight' => 185, 'reps' => 5]);
        LiftSet::factory()->create(['lift_log_id' => $liftLog2->id, 'weight' => 175, 'reps' => 6]);

        // Act: Visit the mobile-entry lifts page
        $response = $this->actingAs($this->user)->get(route('mobile-entry.lifts'));

        // Assert: Page shows both lift logs with appropriate notes
        $response->assertStatus(200);
        
        // Both should have "Your notes:" prefix
        $response->assertSeeInOrder(['Your notes:', 'Your notes:'], false);
        
        // First should show actual comment, second should show N/A
        $response->assertSee('Great workout!', false);
        $response->assertSee('N/A', false);
    }

    /** @test */
    public function it_applies_correct_css_classes_for_notes_styling()
    {
        // Arrange: Create a lift log with notes
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'comments' => 'Test notes for CSS verification',
            'logged_at' => now()
        ]);
        
        LiftSet::factory()->create([
            'lift_log_id' => $liftLog->id,
            'weight' => 200,
            'reps' => 4
        ]);

        // Act: Visit the mobile-entry lifts page
        $response = $this->actingAs($this->user)->get(route('mobile-entry.lifts'));

        // Assert: Correct CSS classes are applied
        $response->assertStatus(200);
        
        // Should have component-message with neutral type
        $response->assertSee('component-message component-message--neutral', false);
        
        // Should be in a subitem structure
        $response->assertSee('component-table-subitem', false);
        
        // Notes should not have additional borders/padding due to CSS override
        $response->assertSee('Test notes for CSS verification', false);
    }

    /** @test */
    public function it_handles_long_notes_properly()
    {
        // Arrange: Create a lift log with very long notes
        $longNotes = 'This is a very long note that should wrap properly and not break the layout. ' .
                    'It contains multiple sentences and should demonstrate how the notes display ' .
                    'handles longer text content without issues. The text should wrap naturally ' .
                    'and maintain good readability on mobile devices.';
        
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'comments' => $longNotes,
            'logged_at' => now()
        ]);
        
        LiftSet::factory()->create([
            'lift_log_id' => $liftLog->id,
            'weight' => 165,
            'reps' => 7
        ]);

        // Act: Visit the mobile-entry lifts page
        $response = $this->actingAs($this->user)->get(route('mobile-entry.lifts'));

        // Assert: Long notes are displayed properly
        $response->assertStatus(200);
        $response->assertSee('Your notes:', false);
        $response->assertSee($longNotes, false);
    }

    /** @test */
    public function it_handles_notes_with_special_characters()
    {
        // Arrange: Create a lift log with notes containing special characters
        $specialNotes = 'Notes with special chars: @#$%^&*()_+ "quotes" \'apostrophes\' <tags> & entities';
        
        $liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'comments' => $specialNotes,
            'logged_at' => now()
        ]);
        
        LiftSet::factory()->create([
            'lift_log_id' => $liftLog->id,
            'weight' => 145,
            'reps' => 9
        ]);

        // Act: Visit the mobile-entry lifts page
        $response = $this->actingAs($this->user)->get(route('mobile-entry.lifts'));

        // Assert: Special characters are handled properly (escaped for HTML)
        $response->assertStatus(200);
        $response->assertSee('Your notes:', false);
        
        // The content should be properly escaped in HTML
        $response->assertSee('Notes with special chars:', false);
        $response->assertSee('quotes', false);
        $response->assertSee('apostrophes', false);
    }
}