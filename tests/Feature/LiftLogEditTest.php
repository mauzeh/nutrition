<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\LiftSet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiftLogEditTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Exercise $exercise;
    protected LiftLog $liftLog;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->exercise = Exercise::factory()->create(['user_id' => $this->user->id]);
        $this->liftLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'comments' => 'Original comments',
        ]);
        $this->liftLog->liftSets()->create([
            'weight' => 100,
            'reps' => 5,
            'notes' => 'Original comments',
        ]);
        $this->liftLog->liftSets()->create([
            'weight' => 100,
            'reps' => 5,
            'notes' => 'Original comments',
        ]);
        $this->liftLog->liftSets()->create([
            'weight' => 100,
            'reps' => 5,
            'notes' => 'Original comments',
        ]);
    }

    /** @test */
    public function user_can_view_edit_page_for_their_lift_log()
    {
        $response = $this->actingAs($this->user)
            ->get(route('lift-logs.edit', $this->liftLog));

        $response->assertStatus(200);
        $response->assertSee($this->exercise->title);
        $response->assertSee('100'); // weight
        $response->assertSee('5'); // reps
        $response->assertSee('3'); // sets/rounds
        $response->assertSee('Original comments');
    }

    /** @test */
    public function user_cannot_view_edit_page_for_another_users_lift_log()
    {
        $otherUser = User::factory()->create();
        $otherLiftLog = LiftLog::factory()->create([
            'user_id' => $otherUser->id,
            'exercise_id' => $this->exercise->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('lift-logs.edit', $otherLiftLog));

        $response->assertStatus(403);
    }

    /** @test */
    public function guest_cannot_view_edit_page()
    {
        $response = $this->get(route('lift-logs.edit', $this->liftLog));

        $response->assertRedirect(route('login'));
    }

    /** @test */
    public function edit_page_uses_flexible_component_system()
    {
        $response = $this->actingAs($this->user)
            ->get(route('lift-logs.edit', $this->liftLog));

        $response->assertStatus(200);
        $response->assertViewIs('mobile-entry.flexible');
        $response->assertViewHas('data');
        
        $data = $response->viewData('data');
        $this->assertArrayHasKey('components', $data);
        $this->assertCount(1, $data['components']);
        $this->assertEquals('form', $data['components'][0]['type']);
    }

    /** @test */
    public function edit_form_is_prepopulated_with_existing_data()
    {
        $response = $this->actingAs($this->user)
            ->get(route('lift-logs.edit', $this->liftLog));

        $response->assertStatus(200);
        
        $data = $response->viewData('data');
        $formData = $data['components'][0]['data'];
        
        // Check form has correct data
        $this->assertEquals('edit-lift-' . $this->liftLog->id, $formData['id']);
        
        // Check numeric fields have correct default values
        $numericFields = collect($formData['numericFields']);
        
        $weightField = $numericFields->firstWhere('name', 'weight');
        $this->assertEquals(100, $weightField['defaultValue']);
        
        $repsField = $numericFields->firstWhere('name', 'reps');
        $this->assertEquals(5, $repsField['defaultValue']);
        
        $roundsField = $numericFields->firstWhere('name', 'rounds');
        $this->assertEquals(3, $roundsField['defaultValue']);
        
        $commentsField = $numericFields->firstWhere('name', 'comments');
        $this->assertEquals('Original comments', $commentsField['defaultValue']);
    }

    /** @test */
    public function edit_form_has_correct_action_and_method()
    {
        $response = $this->actingAs($this->user)
            ->get(route('lift-logs.edit', $this->liftLog));

        $response->assertStatus(200);
        
        $data = $response->viewData('data');
        $formData = $data['components'][0]['data'];
        
        $this->assertEquals(route('lift-logs.update', $this->liftLog->id), $formData['formAction']);
        $this->assertEquals('PUT', $formData['method']);
        $this->assertArrayHasKey('_method', $formData['hiddenFields']);
        $this->assertEquals('PUT', $formData['hiddenFields']['_method']);
    }

    /** @test */
    public function edit_form_has_delete_button()
    {
        $response = $this->actingAs($this->user)
            ->get(route('lift-logs.edit', $this->liftLog));

        $response->assertStatus(200);
        
        $data = $response->viewData('data');
        $formData = $data['components'][0]['data'];
        
        $this->assertNotNull($formData['deleteAction']);
        $this->assertEquals(route('lift-logs.destroy', $this->liftLog), $formData['deleteAction']);
    }

    /** @test */
    public function edit_page_captures_redirect_to_parameter()
    {
        $response = $this->actingAs($this->user)
            ->get(route('lift-logs.edit', [
                'lift_log' => $this->liftLog,
                'redirect_to' => 'mobile-entry-lifts',
                'date' => '2024-01-15'
            ]));

        $response->assertStatus(200);
        
        $data = $response->viewData('data');
        $formData = $data['components'][0]['data'];
        
        $this->assertArrayHasKey('redirect_to', $formData['hiddenFields']);
        $this->assertEquals('mobile-entry-lifts', $formData['hiddenFields']['redirect_to']);
    }

    /** @test */
    public function update_redirects_to_mobile_entry_when_redirect_to_provided()
    {
        $response = $this->actingAs($this->user)
            ->put(route('lift-logs.update', $this->liftLog), [
                'exercise_id' => $this->exercise->id,
                'weight' => 120,
                'reps' => 6,
                'rounds' => 4,
                'comments' => 'Updated comments',
                'date' => $this->liftLog->logged_at->format('Y-m-d'),
                'logged_at' => $this->liftLog->logged_at->format('H:i'),
                'redirect_to' => 'mobile-entry-lifts',
            ]);

        $response->assertRedirect(route('mobile-entry.lifts', [
            'date' => $this->liftLog->logged_at->format('Y-m-d'),
            'submitted_lift_log_id' => $this->liftLog->id,
        ]));
        $response->assertSessionHas('success', 'Lift log updated successfully.');
    }

    /** @test */
    public function update_redirects_to_exercise_logs_by_default()
    {
        $response = $this->actingAs($this->user)
            ->put(route('lift-logs.update', $this->liftLog), [
                'exercise_id' => $this->exercise->id,
                'weight' => 120,
                'reps' => 6,
                'rounds' => 4,
                'comments' => 'Updated comments',
                'date' => $this->liftLog->logged_at->format('Y-m-d'),
                'logged_at' => $this->liftLog->logged_at->format('H:i'),
            ]);

        $response->assertRedirect(route('exercises.show-logs', ['exercise' => $this->exercise->id]));
        $response->assertSessionHas('success', 'Lift log updated successfully.');
    }

    /** @test */
    public function edit_page_works_for_bodyweight_exercises()
    {
        $bodyweightExercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'bodyweight'
        ]);
        $bodyweightLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $bodyweightExercise->id,
        ]);
        $bodyweightLog->liftSets()->create([
            'weight' => 0,
            'reps' => 10,
            'notes' => 'Bodyweight set',
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('lift-logs.edit', $bodyweightLog));

        $response->assertStatus(200);
        $response->assertSee($bodyweightExercise->title);
        $response->assertSee('10'); // reps
    }

    /** @test */
    public function edit_page_works_for_banded_exercises()
    {
        $bandedExercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'banded_resistance'
        ]);
        $bandedLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $bandedExercise->id,
        ]);
        $bandedLog->liftSets()->create([
            'weight' => 0,
            'reps' => 12,
            'band_color' => 'red',
            'notes' => 'Banded set',
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('lift-logs.edit', $bandedLog));

        $response->assertStatus(200);
        $response->assertSee($bandedExercise->title);
        $response->assertSee('12'); // reps
    }

    /** @test */
    public function edit_form_button_text_says_update()
    {
        $response = $this->actingAs($this->user)
            ->get(route('lift-logs.edit', $this->liftLog));

        $response->assertStatus(200);
        
        $data = $response->viewData('data');
        $formData = $data['components'][0]['data'];
        
        $this->assertStringContainsString('Update', $formData['buttons']['submit']);
        $this->assertStringContainsString($this->exercise->title, $formData['buttons']['submit']);
    }

    /** @test */
    public function edit_page_applies_exercise_aliases()
    {
        // Create an alias for the exercise
        $this->exercise->aliases()->create([
            'user_id' => $this->user->id,
            'alias_name' => 'My Custom Name'
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('lift-logs.edit', $this->liftLog));

        $response->assertStatus(200);
        $response->assertSee('My Custom Name');
        $response->assertDontSee($this->exercise->title);
    }

    /** @test */
    public function edit_page_shows_friendly_date_message()
    {
        // Test with today's date
        $todayLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now(),
        ]);
        $todayLog->liftSets()->create(['weight' => 100, 'reps' => 5]);

        $response = $this->actingAs($this->user)
            ->get(route('lift-logs.edit', $todayLog));

        $response->assertStatus(200);
        $response->assertSee('Date:');
        $response->assertSee('Today');

        // Test with yesterday's date
        $yesterdayLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $yesterdayLog->liftSets()->create(['weight' => 100, 'reps' => 5]);

        $response = $this->actingAs($this->user)
            ->get(route('lift-logs.edit', $yesterdayLog));

        $response->assertStatus(200);
        $response->assertSee('Date:');
        $response->assertSee('Yesterday');

        // Test with a date 3 days ago
        $threeDaysAgoLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->exercise->id,
            'logged_at' => now()->subDays(3),
        ]);
        $threeDaysAgoLog->liftSets()->create(['weight' => 100, 'reps' => 5]);

        $response = $this->actingAs($this->user)
            ->get(route('lift-logs.edit', $threeDaysAgoLog));

        $response->assertStatus(200);
        
        $data = $response->viewData('data');
        $formData = $data['components'][0]['data'];
        
        // Check that the date message exists
        $this->assertNotEmpty($formData['messages']);
        $this->assertEquals('info', $formData['messages'][0]['type']);
        $this->assertEquals('Date:', $formData['messages'][0]['prefix']);
        $this->assertStringContainsString('3 days ago', $formData['messages'][0]['text']);
    }

    /** @test */
    public function edit_page_works_for_static_hold_exercises()
    {
        $staticHoldExercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'static_hold'
        ]);
        $staticHoldLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $staticHoldExercise->id,
        ]);
        $staticHoldLog->liftSets()->create([
            'weight' => 0,
            'reps' => 1,
            'time' => 31,
            'notes' => 'Static hold set',
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('lift-logs.edit', $staticHoldLog));

        $response->assertStatus(200);
        $response->assertSee($staticHoldExercise->title);
        
        $data = $response->viewData('data');
        $formData = $data['components'][0]['data'];
        
        // Check numeric fields have correct default values
        $numericFields = collect($formData['numericFields']);
        
        $timeField = $numericFields->firstWhere('name', 'time');
        $this->assertNotNull($timeField, 'Time field should exist for static hold exercises');
        $this->assertEquals(31, $timeField['defaultValue'], 'Time field should be prepopulated with 31 seconds');
        
        $weightField = $numericFields->firstWhere('name', 'weight');
        $this->assertEquals(0, $weightField['defaultValue']);
    }

    /** @test */
    public function edit_page_prepopulates_time_field_for_weighted_static_hold()
    {
        $staticHoldExercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'static_hold'
        ]);
        $staticHoldLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $staticHoldExercise->id,
        ]);
        // Create multiple sets with different durations
        $staticHoldLog->liftSets()->create([
            'weight' => 25,
            'reps' => 1,
            'time' => 45,
            'notes' => 'First set',
        ]);
        $staticHoldLog->liftSets()->create([
            'weight' => 25,
            'reps' => 1,
            'time' => 40,
            'notes' => 'Second set',
        ]);
        $staticHoldLog->liftSets()->create([
            'weight' => 25,
            'reps' => 1,
            'time' => 38,
            'notes' => 'Third set',
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('lift-logs.edit', $staticHoldLog));

        $response->assertStatus(200);
        
        $data = $response->viewData('data');
        $formData = $data['components'][0]['data'];
        
        // Check numeric fields have correct default values from first set
        $numericFields = collect($formData['numericFields']);
        
        $timeField = $numericFields->firstWhere('name', 'time');
        $this->assertNotNull($timeField, 'Time field should exist for static hold exercises');
        $this->assertEquals(45, $timeField['defaultValue'], 'Time field should be prepopulated with first set duration (45 seconds)');
        
        $weightField = $numericFields->firstWhere('name', 'weight');
        $this->assertEquals(25, $weightField['defaultValue'], 'Weight field should be prepopulated with 25 lbs');
        
        $roundsField = $numericFields->firstWhere('name', 'rounds');
        $this->assertEquals(3, $roundsField['defaultValue'], 'Rounds field should show 3 sets');
    }

    /** @test */
    public function edit_page_renders_synced_timed_reps_log_without_exception()
    {
        $timedExercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'timed_output',
            'log_type' => 'timed-reps',
        ]);
        $timedLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $timedExercise->id,
        ]);
        $timedLog->liftSets()->create([
            'weight' => 0,
            'time' => 45,
            'reps' => 12,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('lift-logs.edit', $timedLog));

        $response->assertStatus(200);
        $response->assertSee('45');
        $response->assertSee('12');
    }

    /** @test */
    public function form_field_blade_partial_renders_defensively_without_aria_labels()
    {
        $fieldDefWithoutAria = [
            'id' => 'test-field',
            'name' => 'test_field',
            'label' => 'Test Field:',
            'type' => 'text',
            'defaultValue' => 'test',
        ];

        $html = view('mobile-entry.components.form-field', [
            'field' => $fieldDefWithoutAria,
            'data' => [
                'buttons' => [
                    'decrement' => '-',
                    'increment' => '+',
                ],
            ],
        ])->render();

        $this->assertStringContainsString('aria-label=""', $html);
    }
}
