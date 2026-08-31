<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Tests\Helpers\TriggersPRDetection;
use App\Models\User;

class LiftLogLoggingTest extends TestCase {
    use TriggersPRDetection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /** @test */
    public function a_user_can_view_the_lift_log_logging_page()
    {
        $exercise = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id]);
        $response = $this->get(route('exercises.show-logs', ['exercise' => $exercise->id]));

        $response->assertStatus(200);
    }

    /** @test */
    public function a_user_can_create_a_lift_log()
    {
        $exercise = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id]);

        $now = now();
        $testTime = '14:30'; // Use a time that's already on 15-minute interval

        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 100,
            'reps' => 5,
            'rounds' => 3,
            'comments' => 'Test lift log comments',
            'date' => $now->format('Y-m-d'),
            'logged_at' => $testTime,
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        $this->assertDatabaseHas('lift_logs', [
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'comments' => 'Test lift log comments',
            'logged_at' => \Carbon\Carbon::parse($now->format('Y-m-d') . ' ' . $testTime)->format('Y-m-d H:i:s'),
        ]);

        $liftLog = \App\Models\LiftLog::where('exercise_id', $exercise->id)->first();

        $this->assertDatabaseCount('lift_sets', 3);
        $this->assertDatabaseHas('lift_sets', [
            'lift_log_id' => $liftLog->id,
            'weight' => 100,
            'reps' => 5,
            'notes' => 'Test lift log comments',
        ]);

        $response->assertRedirect(route('exercises.show-logs', ['exercise' => $exercise->id]));
        
        // Check that we have a celebratory success message containing the exercise name and workout details
        $successMessage = session('success');
        $this->assertStringContainsString($exercise->title, $successMessage);
        $this->assertStringContainsString('100 lbs', $successMessage);
        $this->assertStringContainsString('5 reps', $successMessage);
    }

    /** @test */
    public function a_user_can_update_a_lift_log()
    {
        $exercise = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id]);
        $liftLog = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'comments' => 'Original comments',
        ]);
        $liftLog->liftSets()->create([
            'weight' => 100,
            'reps' => 5,
            'notes' => 'Original comments',
        ]);

        // dd($liftLog->toArray()); // Inspect initial lift log data

        $updatedExercise = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id]);
        $updatedLiftLogData = [
            'exercise_id' => $updatedExercise->id,
            'weight' => 120,
            'reps' => 6,
            'rounds' => 4,
            'comments' => 'Updated comments',
            'date' => $liftLog->logged_at->format('Y-m-d'),
            'logged_at' => $liftLog->logged_at->format('H:i'),
        ];

        $response = $this->put(route('lift-logs.update', $liftLog->id), $updatedLiftLogData);

        // dd(\App\Models\LiftLog::find($liftLog->id)->toArray()); // Inspect lift log data after update

        $this->assertDatabaseHas('lift_logs', [
            'id' => $liftLog->id,
            'user_id' => $this->user->id,
            'exercise_id' => $updatedExercise->id,
            'comments' => 'Updated comments',
        ]);

        $this->assertCount(4, $liftLog->liftSets()->get());
        $this->assertCount(5, $liftLog->liftSets()->withTrashed()->get());

        $this->assertDatabaseHas('lift_sets', [
            'lift_log_id' => $liftLog->id,
            'weight' => 120,
            'reps' => 6,
            'notes' => 'Updated comments',
        ]);

        $response->assertRedirect(route('exercises.show-logs', ['exercise' => $updatedExercise->id]))->assertSessionHas('success', 'Lift log updated successfully.');
    }

    /** @test */
    public function a_user_can_view_exercise_logs_page(): void
    {
        $exercise = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id]);

        $response = $this->get('/exercises/' . $exercise->id . '/logs');

        $response->assertStatus(200);
        $response->assertSee($exercise->name);
    }

    /** @test */
    public function a_user_can_view_lift_logs_on_exercise_logs_page()
    {
        $backSquat = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id, 'title' => 'Back Squat']);

        $liftLog1 = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $backSquat->id,
            'comments' => 'Squat lift log 1 comments',
        ]);
        $liftLog1->liftSets()->create([
            'weight' => 200,
            'reps' => 5,
            'notes' => 'Squat lift log 1 comments',
        ]);
        $liftLog1->liftSets()->create([
            'weight' => 200,
            'reps' => 5,
            'notes' => 'Squat lift log 1 comments',
        ]);
        $liftLog1->liftSets()->create([
            'weight' => 200,
            'reps' => 5,
            'notes' => 'Squat lift log 1 comments',
        ]);

        $liftLog2 = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $backSquat->id,
            'comments' => 'Deadlift comments',
        ]);
        $liftLog2->liftSets()->create([
            'weight' => 300,
            'reps' => 3,
            'notes' => 'Deadlift comments',
        ]);

        $response = $this->get('/exercises/' . $backSquat->id . '/logs');
        $response->assertStatus(200);

        // Assert Back Squat lift log details
        $response->assertSee($backSquat->title);
        $response->assertSee($liftLog1->display_weight . ' lbs');
        $response->assertSee($liftLog1->display_rounds . ' x ' . $liftLog1->display_reps);
        $response->assertSee($liftLog1->comments);
        $response->assertSee($liftLog2->display_weight . ' lbs');
        $response->assertSee($liftLog2->display_rounds . ' x ' . $liftLog2->display_reps);
        $response->assertSee($liftLog2->comments);
    }

    /** @test */
    public function a_user_can_create_a_bodyweight_lift_log()
    {
        $exercise = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id, 'exercise_type' => 'bodyweight']);

        $now = now();
        $testTime = '09:15'; // Use a time that's already on 15-minute interval

        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 0, // Bodyweight exercise, weight should be 0
            'reps' => 8,
            'rounds' => 3,
            'comments' => 'Bodyweight lift log comments',
            'date' => $now->format('Y-m-d'),
            'logged_at' => $testTime,
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        $this->assertDatabaseHas('lift_logs', [
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'comments' => 'Bodyweight lift log comments',
            'logged_at' => \Carbon\Carbon::parse($now->format('Y-m-d') . ' ' . $testTime)->format('Y-m-d H:i:s'),
        ]);

        $liftLog = \App\Models\LiftLog::where('exercise_id', $exercise->id)->first();

        $this->assertDatabaseCount('lift_sets', 3);
        $this->assertDatabaseHas('lift_sets', [
            'lift_log_id' => $liftLog->id,
            'weight' => 0,
            'reps' => 8,
            'notes' => 'Bodyweight lift log comments',
        ]);

        $response->assertRedirect(route('exercises.show-logs', ['exercise' => $exercise->id]));
        
        // Check that we have a celebratory success message containing the exercise name and workout details
        $successMessage = session('success');
        $this->assertStringContainsString($exercise->title, $successMessage);
        $this->assertStringContainsString('8 reps', $successMessage);
    }

    /** @test */
    public function a_user_can_update_a_bodyweight_lift_log()
    {
        $exercise = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id, 'exercise_type' => 'bodyweight']);
        $liftLog = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'comments' => 'Original bodyweight comments',
        ]);
        $liftLog->liftSets()->create([
            'weight' => 0,
            'reps' => 5,
            'notes' => 'Original bodyweight comments',
        ]);

        $updatedExercise = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id, 'exercise_type' => 'bodyweight']);
        $updatedLiftLogData = [
            'exercise_id' => $updatedExercise->id,
            'weight' => 0,
            'reps' => 10,
            'rounds' => 4,
            'comments' => 'Updated bodyweight comments',
            'date' => $liftLog->logged_at->format('Y-m-d'),
            'logged_at' => $liftLog->logged_at->format('H:i'),
        ];

        $response = $this->put(route('lift-logs.update', $liftLog->id), $updatedLiftLogData);

        $this->assertDatabaseHas('lift_logs', [
            'id' => $liftLog->id,
            'user_id' => $this->user->id,
            'exercise_id' => $updatedExercise->id,
            'comments' => 'Updated bodyweight comments',
        ]);

        $this->assertCount(4, $liftLog->liftSets()->get());
        $this->assertCount(5, $liftLog->liftSets()->withTrashed()->get());

        $this->assertDatabaseHas('lift_sets', [
            'lift_log_id' => $liftLog->id,
            'weight' => 0,
            'reps' => 10,
            'notes' => 'Updated bodyweight comments',
        ]);

        $response->assertRedirect(route('exercises.show-logs', ['exercise' => $updatedExercise->id]))->assertSessionHas('success', 'Lift log updated successfully.');
    }

    /** @test */
    public function a_user_can_view_bodyweight_exercise_logs_with_correct_weight_display()
    {
        $exercise = \App\Models\Exercise::factory()->create([
            'user_id' => $this->user->id, 
            'title' => 'Chin-Ups', 
            'exercise_type' => 'bodyweight'
        ]);
        $liftLog = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);
        $liftLog->liftSets()->create([
            'weight' => 0,
            'reps' => 5,
            'notes' => 'Bodyweight set',
        ]);

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        // The flexible view uses badges for display, so check for the exercise title and reps
        $response->assertSee($exercise->title);
        $response->assertSee('1 x 5'); // Badge format: rounds x reps
    }

    /** @test */
    public function a_user_can_view_bodyweight_exercise_logs_with_correct_1rm_display()
    {
        $exercise = \App\Models\Exercise::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Chin-Ups',
            'exercise_type' => 'bodyweight'
        ]);
        $liftLog = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);
        $liftLog->liftSets()->create([
            'weight' => 0,
            'reps' => 5,
            'notes' => 'Bodyweight set',
        ]);

        $response = $this->get(route('exercises.show-logs', $exercise));

        $response->assertStatus(200);
        // Bodyweight exercises should not show 1RM calculations
        $response->assertDontSee('lbs (est. incl. BW)');
    }

    /** @test */
    public function a_user_can_create_a_static_hold_lift_log()
    {
        $exercise = \App\Models\Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'static_hold',
            'title' => 'L-sit'
        ]);

        $now = now();
        $testTime = '10:00';

        $liftLogData = [
            'exercise_id' => $exercise->id,
            'time' => 45, // 45 seconds hold
            'weight' => 0, // Bodyweight
            'rounds' => 3,
            'comments' => 'Static hold comments',
            'date' => $now->format('Y-m-d'),
            'logged_at' => $testTime,
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        $this->assertDatabaseHas('lift_logs', [
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'comments' => 'Static hold comments',
            'logged_at' => \Carbon\Carbon::parse($now->format('Y-m-d') . ' ' . $testTime)->format('Y-m-d H:i:s'),
        ]);

        $liftLog = \App\Models\LiftLog::where('exercise_id', $exercise->id)->first();

        $this->assertDatabaseCount('lift_sets', 3);
        $this->assertDatabaseHas('lift_sets', [
            'lift_log_id' => $liftLog->id,
            'weight' => 0,
            'reps' => 1, // Always 1 for static holds
            'time' => 45, // Duration stored in time field
            'notes' => 'Static hold comments',
        ]);

        $response->assertRedirect(route('exercises.show-logs', ['exercise' => $exercise->id]));
        
        // Check success message contains exercise name and duration
        $successMessage = session('success');
        $this->assertStringContainsString($exercise->title, $successMessage);
        $this->assertStringContainsString('45s hold', $successMessage);
    }

    /** @test */
    public function a_user_can_update_a_static_hold_lift_log()
    {
        $exercise = \App\Models\Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'static_hold',
            'title' => 'Plank'
        ]);
        
        $liftLog = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'comments' => 'Original hold',
        ]);
        
        $liftLog->liftSets()->create([
            'weight' => 0,
            'reps' => 1,
            'time' => 30,
            'notes' => 'Original hold',
        ]);

        $now = now();
        $updatedLiftLogData = [
            'exercise_id' => $exercise->id,
            'time' => 60, // Updated to 60 seconds
            'weight' => 10, // Added weight
            'rounds' => 4,
            'comments' => 'Updated hold',
            'date' => $now->format('Y-m-d'),
            'logged_at' => '11:00',
        ];

        $response = $this->put(route('lift-logs.update', $liftLog->id), $updatedLiftLogData);

        $this->assertDatabaseHas('lift_logs', [
            'id' => $liftLog->id,
            'exercise_id' => $exercise->id,
            'comments' => 'Updated hold',
        ]);

        // Old sets should be soft-deleted, new ones created
        $this->assertCount(4, $liftLog->liftSets()->get());
        $this->assertCount(5, $liftLog->liftSets()->withTrashed()->get());
        $this->assertDatabaseHas('lift_sets', [
            'lift_log_id' => $liftLog->id,
            'weight' => 10,
            'reps' => 1,
            'time' => 60,
            'notes' => 'Updated hold',
        ]);

        $response->assertRedirect(route('exercises.show-logs', ['exercise' => $exercise->id]));
    }

    /** @test */
    public function first_lift_log_is_marked_as_pr()
    {
        $exercise = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id]);

        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 100,
            'reps' => 5,
            'rounds' => 3,
            'comments' => 'First lift',
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        // First lift should be marked as PR
        $this->assertNotEmpty(session('is_pr'));
        
        // Success message should contain PR indicator
        $successMessage = session('success');
        $this->assertStringContainsString('PR!', $successMessage);
    }

    /** @test */
    public function heavier_lift_is_marked_as_pr()
    {
        $exercise = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id]);

        // Log first lift at 100 lbs
        $firstLiftLog = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $firstLiftLog->liftSets()->create([
            'weight' => 100,
            'reps' => 5,
            'notes' => 'First lift',
        ]);

        // Log second lift at 120 lbs (heavier = PR)
        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 120,
            'reps' => 5,
            'rounds' => 3,
            'comments' => 'Heavier lift',
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        // Heavier lift should be marked as PR
        $this->assertNotEmpty(session('is_pr'));
        
        // Success message should contain PR indicator
        $successMessage = session('success');
        $this->assertStringContainsString('PR!', $successMessage);
    }

    /** @test */
    public function lighter_lift_is_not_marked_as_pr()
    {
        $exercise = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id]);

        // Log first lift at 120 lbs × 5 reps × 3 sets = 1800 lbs volume
        $firstLiftLog = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $firstLiftLog->liftSets()->createMany([
            ['weight' => 120, 'reps' => 5, 'notes' => 'Heavy lift set 1'],
            ['weight' => 120, 'reps' => 5, 'notes' => 'Heavy lift set 2'],
            ['weight' => 120, 'reps' => 5, 'notes' => 'Heavy lift set 3'],
        ]);
        $this->triggerPRDetection($firstLiftLog);

        // Log second lift at 100 lbs × 5 reps × 3 sets = 1500 lbs volume (lighter weight, less volume = not a PR)
        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 100,
            'reps' => 5,
            'rounds' => 3,
            'comments' => 'Lighter lift',
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        // Lighter lift with less volume should NOT be marked as PR
        $this->assertEmpty(session('is_pr'));
        
        // Success message should NOT contain PR indicator
        $successMessage = session('success');
        $this->assertStringNotContainsString('PR!', $successMessage);
    }

    /** @test */
    public function equal_weight_lift_is_not_marked_as_pr()
    {
        $exercise = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id]);

        // Log first lift at 100 lbs × 5 reps × 3 sets = 1500 lbs volume
        $firstLiftLog = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $firstLiftLog->liftSets()->createMany([
            ['weight' => 100, 'reps' => 5, 'notes' => 'First lift set 1'],
            ['weight' => 100, 'reps' => 5, 'notes' => 'First lift set 2'],
            ['weight' => 100, 'reps' => 5, 'notes' => 'First lift set 3'],
        ]);

        // Log second lift at same weight and volume (equal = not a PR)
        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 100,
            'reps' => 5,
            'rounds' => 3,
            'comments' => 'Same weight and volume',
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        // Equal weight and volume should NOT be marked as PR
        $this->assertEmpty(session('is_pr'));
        
        // Success message should NOT contain PR indicator
        $successMessage = session('success');
        $this->assertStringNotContainsString('PR!', $successMessage);
    }

    /** @test */
    public function bodyweight_exercises_are_marked_as_pr_for_volume()
    {
        $exercise = \App\Models\Exercise::factory()->create([
            'user_id' => $this->user->id,
            'exercise_type' => 'bodyweight'
        ]);

        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 0,
            'reps' => 10,
            'rounds' => 3,
            'comments' => 'Bodyweight exercise',
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        // Bodyweight exercises support Volume PRs (using total reps)
        // First lift should be a Volume PR (flag value 4)
        $response->assertSessionHas('is_pr');
        $prFlags = session('is_pr');
        $this->assertGreaterThan(0, $prFlags, 'First bodyweight lift should be a PR');
        
        // Success message should contain PR indicator
        $successMessage = session('success');
        $this->assertStringContainsString('PR!', $successMessage);
    }

    /** @test */
    public function pr_detection_only_considers_previous_lifts()
    {
        $exercise = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id]);

        // Log first lift at 100 lbs yesterday
        $firstLiftLog = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDay(),
        ]);
        $firstLiftLog->liftSets()->create([
            'weight' => 100,
            'reps' => 5,
            'notes' => 'First lift',
        ]);
        $this->triggerPRDetection($firstLiftLog);

        // Log second lift at 120 lbs × 5 reps × 3 sets today (PR in both 1RM and volume)
        $secondLiftLog = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);
        $secondLiftLog->liftSets()->createMany([
            ['weight' => 120, 'reps' => 5, 'notes' => 'PR lift set 1'],
            ['weight' => 120, 'reps' => 5, 'notes' => 'PR lift set 2'],
            ['weight' => 120, 'reps' => 5, 'notes' => 'PR lift set 3'],
        ]);
        $this->triggerPRDetection($secondLiftLog);

        // Log third lift at 110 lbs × 5 reps × 3 sets tomorrow (not a PR - less weight and less volume)
        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 110,
            'reps' => 5,
            'rounds' => 3,
            'comments' => 'Not a PR',
            'date' => now()->addDay()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        // Should NOT be marked as PR because 120 lbs × 3 sets was already logged
        $this->assertEmpty(session('is_pr'));
    }

    /** @test */
    public function pr_detection_works_independently_for_different_exercises()
    {
        // Create two different exercises
        $backSquat = \App\Models\Exercise::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Back Squat'
        ]);
        $strictPress = \App\Models\Exercise::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Strict Press'
        ]);

        // Log Back Squat at 300 lbs (heavy exercise)
        $backSquatLog = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $backSquat->id,
            'logged_at' => now()->subDay(),
        ]);
        $backSquatLog->liftSets()->create([
            'weight' => 300,
            'reps' => 5,
            'notes' => 'Heavy squat',
        ]);

        // Log Strict Press at 100 lbs (lighter exercise, but should still be a PR)
        $strictPressData = [
            'exercise_id' => $strictPress->id,
            'weight' => 100,
            'reps' => 5,
            'rounds' => 1,
            'comments' => 'First press',
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $strictPressData);

        // Should be marked as PR even though 100 lbs < 300 lbs
        // because they are different exercises
        $this->assertNotEmpty(session('is_pr'));
        
        $successMessage = session('success');
        $this->assertStringContainsString('PR!', $successMessage);
    }

    /** @test */
    public function pr_badges_display_correctly_for_multiple_exercises_on_same_day()
    {
        // Create two exercises
        $backSquat = \App\Models\Exercise::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Back Squat'
        ]);
        $strictPress = \App\Models\Exercise::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Strict Press'
        ]);

        // Log previous lifts for both exercises
        $prevSquat = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $backSquat->id,
            'logged_at' => now()->subWeek(),
        ]);
        $prevSquat->liftSets()->create(['weight' => 250, 'reps' => 5, 'notes' => '']);

        $prevPress = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $strictPress->id,
            'logged_at' => now()->subWeek(),
        ]);
        $prevPress->liftSets()->create(['weight' => 80, 'reps' => 5, 'notes' => '']);

        // Log PRs for both exercises today
        $squatPR = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $backSquat->id,
            'logged_at' => now(),
        ]);
        $squatPR->liftSets()->create(['weight' => 300, 'reps' => 5, 'notes' => '']);
        $this->triggerPRDetection($squatPR);

        $pressPR = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $strictPress->id,
            'logged_at' => now(),
        ]);
        $pressPR->liftSets()->create(['weight' => 100, 'reps' => 5, 'notes' => '']);
        $this->triggerPRDetection($pressPR);

        // Use the table row builder to generate rows (simulating mobile-entry page)
        $todaysLogs = \App\Models\LiftLog::where('user_id', $this->user->id)
            ->whereDate('logged_at', now()->toDateString())
            ->with(['exercise', 'liftSets'])
            ->get();

        $builder = app(\App\Services\LiftLogTableRowBuilder::class);
        $rows = $builder->buildRows($todaysLogs, [
            'showDateBadge' => false,
            'showCheckbox' => false,
        ]);

        // Both should have PR badges
        $squatRow = collect($rows)->firstWhere('id', $squatPR->id);
        $pressRow = collect($rows)->firstWhere('id', $pressPR->id);

        $this->assertNotNull($squatRow);
        $this->assertNotNull($pressRow);

        // Check that both have PR badges
        $squatBadges = collect($squatRow['badges'])->pluck('text')->toArray();
        $pressBadges = collect($pressRow['badges'])->pluck('text')->toArray();

        $this->assertContains('🏆 PR', $squatBadges, 'Back Squat should have PR badge');
        $this->assertContains('🏆 PR', $pressBadges, 'Strict Press should have PR badge');
    }

    /** @test */
    public function mobile_entry_lifts_page_sets_all_prs_flag_when_all_lifts_are_prs()
    {
        $exercise = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id]);

        // Log a previous lift
        $prevLog = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $prevLog->liftSets()->create(['weight' => 100, 'reps' => 5, 'notes' => '']);
        $this->triggerPRDetection($prevLog);

        // Log a PR today
        $prLog = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);
        $prLog->liftSets()->create(['weight' => 120, 'reps' => 5, 'notes' => '']);
        $this->triggerPRDetection($prLog);

        // Visit mobile-entry lifts page
        $response = $this->get(route('mobile-entry.lifts'));

        $response->assertStatus(200);
        $response->assertViewHas('data', function ($data) {
            return isset($data['has_prs']) && $data['has_prs'] === true;
        });
    }

    /** @test */
    public function mobile_entry_lifts_page_sets_all_prs_flag_when_at_least_one_lift_is_pr()
    {
        $exercise1 = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id]);
        $exercise2 = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id]);

        // Log previous lifts
        $prevLog1 = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise1->id,
            'logged_at' => now()->subWeek(),
        ]);
        $prevLog1->liftSets()->create(['weight' => 100, 'reps' => 5, 'notes' => '']);
        $this->triggerPRDetection($prevLog1);

        $prevLog2 = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise2->id,
            'logged_at' => now()->subWeek(),
        ]);
        $prevLog2->liftSets()->create(['weight' => 200, 'reps' => 5, 'notes' => '']);
        $this->triggerPRDetection($prevLog2);

        // Log one PR and one non-PR today
        $prLog = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise1->id,
            'logged_at' => now(),
        ]);
        $prLog->liftSets()->create(['weight' => 120, 'reps' => 5, 'notes' => '']);
        $this->triggerPRDetection($prLog);

        $nonPrLog = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise2->id,
            'logged_at' => now(),
        ]);
        $nonPrLog->liftSets()->create(['weight' => 180, 'reps' => 5, 'notes' => '']); // Lighter than previous
        $this->triggerPRDetection($nonPrLog);

        // Visit mobile-entry lifts page
        $response = $this->get(route('mobile-entry.lifts'));

        $response->assertStatus(200);
        $response->assertViewHas('data', function ($data) {
            return isset($data['has_prs']) && $data['has_prs'] === true; // Changed: now true because at least one is a PR
        });
    }

    /** @test */
    public function mobile_entry_lifts_page_does_not_set_all_prs_flag_when_no_lifts_logged()
    {
        // Visit mobile-entry lifts page with no lifts logged
        $response = $this->get(route('mobile-entry.lifts'));

        $response->assertStatus(200);
        $response->assertViewHas('data', function ($data) {
            return isset($data['has_prs']) && $data['has_prs'] === false;
        });
    }

    /** @test */
    public function mobile_entry_lifts_page_does_not_set_all_prs_flag_when_no_lifts_are_prs()
    {
        $exercise = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id]);

        // Log a previous lift
        $prevLog = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $prevLog->liftSets()->create(['weight' => 200, 'reps' => 5, 'notes' => '']);

        // Log a non-PR today (lighter weight)
        $nonPrLog = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now(),
        ]);
        $nonPrLog->liftSets()->create(['weight' => 180, 'reps' => 5, 'notes' => '']);

        // Visit mobile-entry lifts page
        $response = $this->get(route('mobile-entry.lifts'));

        $response->assertStatus(200);
        $response->assertViewHas('data', function ($data) {
            return isset($data['has_prs']) && $data['has_prs'] === false;
        });
    }

    /** @test */
    public function low_rep_lifts_are_marked_as_pr_when_heaviest_for_that_rep_count()
    {
        $exercise = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id]);

        // Log 410 lbs x 4 reps (estimated 1RM ~464 lbs)
        $log410x4 = \App\Models\LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subWeek(),
        ]);
        $log410x4->liftSets()->create(['weight' => 410, 'reps' => 4, 'notes' => '']);

        // Log 430 lbs x 1 rep (actual 1RM = 430, which is less than estimated 464)
        // But this should still be a PR because it's the heaviest 1-rep lift (rep-specific PR)
        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 430,
            'reps' => 1,
            'rounds' => 1,
            'comments' => 'Max attempt',
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        // Should be marked as PR because it's the heaviest 1-rep lift
        $this->assertNotEmpty(session('is_pr'));
        
        $successMessage = session('success');
        $this->assertStringContainsString('PR!', $successMessage);
    }

    /** @test */
    public function rep_specific_pr_detection_works_for_all_low_rep_ranges()
    {
        $exercise = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id]);

        // Log different rep ranges
        $logs = [
            ['weight' => 100, 'reps' => 1],
            ['weight' => 95, 'reps' => 2],
            ['weight' => 90, 'reps' => 3],
            ['weight' => 85, 'reps' => 4],
            ['weight' => 80, 'reps' => 5],
        ];

        foreach ($logs as $logData) {
            $log = \App\Models\LiftLog::factory()->create([
                'user_id' => $this->user->id,
                'exercise_id' => $exercise->id,
                'logged_at' => now()->subWeek(),
            ]);
            $log->liftSets()->create(['weight' => $logData['weight'], 'reps' => $logData['reps'], 'notes' => '']);
            $this->triggerPRDetection($log);
        }

        // Now log heavier weights for each rep range - all should be PRs
        $newLogs = [
            ['weight' => 105, 'reps' => 1],
            ['weight' => 100, 'reps' => 2],
            ['weight' => 95, 'reps' => 3],
            ['weight' => 90, 'reps' => 4],
            ['weight' => 85, 'reps' => 5],
        ];

        foreach ($newLogs as $logData) {
            $log = \App\Models\LiftLog::factory()->create([
                'user_id' => $this->user->id,
                'exercise_id' => $exercise->id,
                'logged_at' => now(),
            ]);
            $log->liftSets()->create(['weight' => $logData['weight'], 'reps' => $logData['reps'], 'notes' => '']);
            $this->triggerPRDetection($log);
        }

        // Use the table row builder to check PR detection
        $todaysLogs = \App\Models\LiftLog::where('user_id', $this->user->id)
            ->whereDate('logged_at', now()->toDateString())
            ->with(['exercise', 'liftSets'])
            ->get();

        $builder = app(\App\Services\LiftLogTableRowBuilder::class);
        $rows = $builder->buildRows($todaysLogs, []);

        // All 5 logs should be marked as PRs
        $this->assertCount(5, $rows);
        foreach ($rows as $row) {
            $badges = collect($row['badges'])->pluck('text')->toArray();
            $this->assertContains('🏆 PR', $badges, 'Each rep-specific PR should have a badge');
        }
    }

    /** @test */
    public function rep_specific_pr_detection_works_for_medium_rep_ranges()
    {
        $exercise = \App\Models\Exercise::factory()->create(['user_id' => $this->user->id]);

        // Log initial lifts for 6-10 rep ranges
        $initialLogs = [
            ['weight' => 135, 'reps' => 6],
            ['weight' => 130, 'reps' => 7],
            ['weight' => 125, 'reps' => 8],
            ['weight' => 120, 'reps' => 9],
            ['weight' => 115, 'reps' => 10],
        ];

        foreach ($initialLogs as $logData) {
            $log = \App\Models\LiftLog::factory()->create([
                'user_id' => $this->user->id,
                'exercise_id' => $exercise->id,
                'logged_at' => now()->subWeek(),
            ]);
            $log->liftSets()->create(['weight' => $logData['weight'], 'reps' => $logData['reps'], 'notes' => '']);
        }

        // Test 6-rep PR detection (like user 26's case)
        $liftLogData = [
            'exercise_id' => $exercise->id,
            'weight' => 145, // 10 lbs heavier than previous 6-rep max of 135
            'reps' => 6,
            'rounds' => 1,
            'comments' => 'New 6-rep PR',
            'date' => now()->format('Y-m-d'),
            'logged_at' => '14:30',
        ];

        $response = $this->post(route('lift-logs.store'), $liftLogData);

        // Should be marked as PR because it's the heaviest 6-rep lift
        $this->assertNotEmpty(session('is_pr'));
        
        $successMessage = session('success');
        $this->assertStringContainsString('PR!', $successMessage);

        // Test that other rep ranges (7-10) also work
        $newPRs = [
            ['weight' => 140, 'reps' => 7],
            ['weight' => 135, 'reps' => 8],
            ['weight' => 130, 'reps' => 9],
            ['weight' => 125, 'reps' => 10],
        ];

        foreach ($newPRs as $logData) {
            $liftLogData = [
                'exercise_id' => $exercise->id,
                'weight' => $logData['weight'],
                'reps' => $logData['reps'],
                'rounds' => 1,
                'comments' => "New {$logData['reps']}-rep PR",
                'date' => now()->format('Y-m-d'),
                'logged_at' => '15:00',
            ];

            $response = $this->post(route('lift-logs.store'), $liftLogData);
            $this->assertNotEmpty(session('is_pr'));
        }
    }


}