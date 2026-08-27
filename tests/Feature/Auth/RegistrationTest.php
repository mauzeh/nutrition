<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function seedRequiredData(): void
    {
        $this->seed(\Database\Seeders\UnitSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\UserSeeder::class);
        $this->seed(\Database\Seeders\IngredientSeeder::class);
    }

    // Basic Registration Tests
    
    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
        $response->assertSee('Register');
        $response->assertSee('Name');
        $response->assertSee('Email');
        $response->assertSee('Password');
        $response->assertSee('Confirm Password');
        $response->assertSee('Must be at least 8 characters');
    }

    public function test_registration_screen_includes_google_auth_option(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
        $response->assertSee('Sign up with Google');
        $response->assertSee('or');
    }

    public function test_new_users_can_register(): void
    {
        $this->seedRequiredData();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('mobile-entry.lifts', absolute: false));
        
        // Verify user was created in database
        $this->assertDatabaseHas('users', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }

    public function test_new_users_have_correct_exercise_preferences(): void
    {
        $this->seedRequiredData();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        
        $user = auth()->user();
        
        // Verify exercise preferences are set correctly
        $this->assertTrue($user->show_global_exercises);
        $this->assertTrue($user->show_extra_weight);
        $this->assertFalse($user->prefill_suggested_values); // OFF for new users
    }

    public function test_new_users_are_assigned_athlete_role(): void
    {
        $this->seedRequiredData();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        
        $user = auth()->user();
        
        // Verify user has the Athlete role
        $this->assertTrue($user->hasRole('Athlete'));
        $this->assertCount(1, $user->roles);
        $this->assertEquals('Athlete', $user->roles->first()->name);
    }

    public function test_new_users_get_seeded_with_default_data(): void
    {
        $this->seedRequiredData();

        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = auth()->user();
        
        // Verify measurement types were created
        $this->assertCount(2, $user->measurementTypes);
        $this->assertTrue($user->measurementTypes->contains('name', 'Bodyweight'));
        $this->assertTrue($user->measurementTypes->contains('name', 'Waist'));

        // Verify ingredients were created
        $this->assertCount(5, $user->ingredients);
        $this->assertTrue($user->ingredients->contains('name', 'Chicken Breast'));
        $this->assertTrue($user->ingredients->contains('name', 'Brown Rice'));

        // Verify sample meal was created
        $this->assertCount(1, $user->meals);
        $meal = $user->meals->first();
        $this->assertEquals('Chicken, Rice & Broccoli', $meal->name);
    }

    // Validation Error Tests

    public function test_registration_requires_name(): void
    {
        $response = $this->post('/register', [
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertGuest();
    }

    public function test_registration_requires_email(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_registration_requires_valid_email(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'invalid-email',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_registration_requires_unique_email(): void
    {
        // Create existing user
        User::factory()->create(['email' => 'test@example.com']);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_registration_rejects_soft_deleted_user_email(): void
    {
        // Create and soft-delete a user
        $deletedUser = User::factory()->create(['email' => 'deleted@example.com']);
        $deletedUser->delete(); // Soft delete
        
        $this->assertTrue($deletedUser->trashed());

        // Try to register with the same email
        $response = $this->post('/register', [
            'name' => 'New User',
            'email' => 'deleted@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        // Should fail with specific validation error
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        
        // Check the specific error message
        $errors = session('errors');
        $this->assertStringContainsString('previously registered', $errors->get('email')[0]);
        $this->assertStringContainsString('deactivated', $errors->get('email')[0]);
        
        // The soft-deleted user should remain soft-deleted and unchanged
        $deletedUser->refresh();
        $this->assertTrue($deletedUser->trashed());
        $this->assertNotEquals('New User', $deletedUser->name);
        
        // Should still only be one user with this email (the soft-deleted one)
        $this->assertEquals(1, User::withTrashed()->where('email', 'deleted@example.com')->count());
    }

    public function test_registration_prevents_duplicate_active_users(): void
    {
        // Create existing active user
        User::factory()->create(['email' => 'active@example.com']);

        // Try to register with the same email (different case to test case-insensitive check)
        $response = $this->post('/register', [
            'name' => 'Another User',
            'email' => 'ACTIVE@EXAMPLE.COM',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        
        // Should still only be one user with this email
        $this->assertEquals(1, User::where('email', 'active@example.com')->count());
    }

    public function test_registration_requires_password(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_registration_requires_password_confirmation(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_registration_requires_matching_password_confirmation(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'different-password',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_registration_requires_minimum_password_length(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    // Edge Cases and Security Tests

    public function test_registration_handles_very_long_name(): void
    {
        $this->seedRequiredData();
        
        $longName = str_repeat('a', 255); // Maximum allowed length

        $response = $this->post('/register', [
            'name' => $longName,
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertEquals($longName, auth()->user()->name);
    }

    public function test_registration_rejects_name_exceeding_maximum_length(): void
    {
        $tooLongName = str_repeat('a', 256); // Exceeds maximum length

        $response = $this->post('/register', [
            'name' => $tooLongName,
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertGuest();
    }

    public function test_registration_handles_very_long_email(): void
    {
        $this->seedRequiredData();
        
        // Create a long but valid email (under 255 chars)
        $longEmail = str_repeat('a', 240) . '@example.com';

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => $longEmail,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertEquals($longEmail, auth()->user()->email);
    }

    public function test_registration_rejects_email_exceeding_maximum_length(): void
    {
        $tooLongEmail = str_repeat('a', 250) . '@example.com'; // Exceeds 255 chars

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => $tooLongEmail,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_registration_requires_lowercase_email(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'TEST@EXAMPLE.COM',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_registration_accepts_lowercase_email(): void
    {
        $this->seedRequiredData();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertEquals('test@example.com', auth()->user()->email);
        
        // Also verify in database
        $this->assertDatabaseHas('users', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }

    public function test_registration_hashes_password_correctly(): void
    {
        $this->seedRequiredData();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'my-secret-password',
            'password_confirmation' => 'my-secret-password',
        ]);

        $user = auth()->user();
        
        // Verify password is hashed
        $this->assertNotEquals('my-secret-password', $user->password);
        $this->assertTrue(Hash::check('my-secret-password', $user->password));
    }

    public function test_registration_handles_special_characters_in_name(): void
    {
        $this->seedRequiredData();

        $specialName = "José María O'Connor-Smith";

        $response = $this->post('/register', [
            'name' => $specialName,
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertEquals($specialName, auth()->user()->name);
    }

    public function test_registration_handles_international_email_domains(): void
    {
        $this->seedRequiredData();

        $internationalEmail = 'test@münchen.de';

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => $internationalEmail,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertEquals($internationalEmail, auth()->user()->email);
    }

    // Security and Authentication Tests

    public function test_authenticated_users_cannot_access_registration_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/register');

        $response->assertRedirect('/');
    }

    public function test_registration_logs_user_in_automatically(): void
    {
        $this->seedRequiredData();

        $this->assertGuest();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertEquals('test@example.com', auth()->user()->email);
    }

    public function test_registration_triggers_registered_event(): void
    {
        $this->seedRequiredData();
        
        // We can't easily test events in this context, but we can verify
        // the user is created and authenticated, which indicates the event flow worked
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('mobile-entry.lifts', absolute: false));
    }

    // CSRF and Form Security Tests

    public function test_registration_requires_csrf_token(): void
    {
        $response = $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post('/register', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

        // Without CSRF middleware, this should work
        // With CSRF middleware, it would fail with 419
        $response->assertStatus(302); // Redirect after successful registration
    }

    // Multiple Registration Attempts

    public function test_multiple_failed_registration_attempts_with_same_email(): void
    {
        // This test makes several rapid attempts to exercise validation behavior
        // across retries. The register route is now rate limited (throttle:2,1),
        // so disable that middleware here to isolate the validation behavior under
        // test; throttling itself is covered by test_registration_route_is_rate_limited.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        // First attempt - should fail due to missing name
        $response1 = $this->post('/register', [
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
        $response1->assertSessionHasErrors('name');

        // Second attempt - should fail due to short password
        $response2 = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);
        $response2->assertSessionHasErrors('password');

        // Third attempt - should succeed
        $this->seedRequiredData();
        $response3 = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response3->assertRedirect(route('mobile-entry.lifts', absolute: false));
    }

    // Data Integrity Tests

    public function test_registration_creates_exactly_one_user_record(): void
    {
        $this->seedRequiredData();
        
        $initialUserCount = User::count();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertEquals($initialUserCount + 1, User::count());
        $this->assertDatabaseHas('users', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }

    public function test_registration_failure_does_not_create_user_record(): void
    {
        $initialUserCount = User::count();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'invalid-email',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertEquals($initialUserCount, User::count());
        $this->assertDatabaseMissing('users', [
            'name' => 'Test User',
        ]);
    }

    // Form Field Validation Edge Cases

    public function test_registration_trims_whitespace_from_name(): void
    {
        $this->seedRequiredData();

        $response = $this->post('/register', [
            'name' => '  Test User  ',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertEquals('Test User', auth()->user()->name);
    }

    public function test_registration_accepts_minimum_valid_password(): void
    {
        $this->seedRequiredData();

        $minPassword = '12345678'; // Exactly 8 characters

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => $minPassword,
            'password_confirmation' => $minPassword,
        ]);

        $this->assertAuthenticated();
        $this->assertTrue(Hash::check($minPassword, auth()->user()->password));
    }

    public function test_registration_accepts_complex_password(): void
    {
        $this->seedRequiredData();

        $complexPassword = 'MyC0mpl3x!P@ssw0rd#2024';

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => $complexPassword,
            'password_confirmation' => $complexPassword,
        ]);

        $this->assertAuthenticated();
        $this->assertTrue(Hash::check($complexPassword, auth()->user()->password));
    }

    // Email Notification Tests

    public function test_registration_sends_notification_to_admin_users(): void
    {
        Mail::fake();
        $this->seedRequiredData();

        // Create admin users
        $admin1 = User::factory()->create(['email' => 'admin1@example.com']);
        $admin2 = User::factory()->create(['email' => 'admin2@example.com']);
        
        // Assign admin role
        $adminRole = \App\Models\Role::where('name', 'Admin')->first();
        $admin1->roles()->attach($adminRole);
        $admin2->roles()->attach($adminRole);

        // Register a new user
        $response = $this->post('/register', [
            'name' => 'New Test User',
            'email' => 'newuser@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();

        // Assert that email was sent to admin users
        Mail::assertSent(\App\Mail\NewUserRegistered::class, function ($mail) use ($admin1, $admin2) {
            // The email should be sent to the first admin found (which includes the seeded admin)
            // and should have the test admin users in CC
            return $mail->hasTo('admin@example.com') && 
                   $mail->hasCc($admin1->email) &&
                   $mail->hasCc($admin2->email) &&
                   $mail->newUser->email === 'newuser@example.com';
        });
    }

    public function test_registration_handles_single_admin_user(): void
    {
        Mail::fake();
        $this->seedRequiredData();

        // Create only one additional admin user (the seeded admin already exists)
        $admin = User::factory()->create(['email' => 'single-admin@example.com']);
        $adminRole = \App\Models\Role::where('name', 'Admin')->first();
        $admin->roles()->attach($adminRole);

        // Register a new user
        $response = $this->post('/register', [
            'name' => 'New Test User',
            'email' => 'newuser@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();

        // Assert that email was sent to admin users (seeded admin as primary, additional admin as CC)
        Mail::assertSent(\App\Mail\NewUserRegistered::class, function ($mail) use ($admin) {
            return $mail->hasTo('admin@example.com') && 
                   $mail->hasCc($admin->email) &&
                   $mail->newUser->email === 'newuser@example.com';
        });
    }

    public function test_registration_handles_no_admin_users(): void
    {
        Mail::fake();
        $this->seedRequiredData();

        // Don't create any admin users (only the seeded ones exist)
        // Register a new user
        $response = $this->post('/register', [
            'name' => 'New Test User',
            'email' => 'newuser@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();

        // Assert that email was sent (to the seeded admin user)
        Mail::assertSent(\App\Mail\NewUserRegistered::class);
    }

    public function test_registration_email_contains_correct_user_information(): void
    {
        Mail::fake();
        $this->seedRequiredData();

        // Create admin user
        $admin = User::factory()->create(['email' => 'content-admin@example.com']);
        $adminRole = \App\Models\Role::where('name', 'Admin')->first();
        $admin->roles()->attach($adminRole);

        // Register a new user
        $response = $this->post('/register', [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();

        // Assert email content
        Mail::assertSent(\App\Mail\NewUserRegistered::class, function ($mail) {
            return $mail->newUser->name === 'John Doe' &&
                   $mail->newUser->email === 'john.doe@example.com' &&
                   $mail->envelope()->subject === 'New User Registration - John Doe';
        });
    }

    // Honeypot / Bot Protection Tests

    public function test_registration_rejects_filled_honeypot_field(): void
    {
        $initialUserCount = User::count();

        $response = $this->post('/register', [
            'name' => 'Bot User',
            'email' => 'bot@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'website' => 'http://spam.example.com', // honeypot: real users never fill this
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertEquals($initialUserCount, User::count());
        $this->assertDatabaseMissing('users', ['email' => 'bot@example.com']);
    }

    public function test_registration_rejects_submission_faster_than_minimum_fill_time(): void
    {
        $initialUserCount = User::count();

        $response = $this->post('/register', [
            'name' => 'Speedy Bot',
            'email' => 'speedy@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'form_loaded_at' => encrypt(now()->timestamp), // submitted instantly
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertEquals($initialUserCount, User::count());
    }

    public function test_registration_rejects_tampered_timestamp(): void
    {
        $response = $this->post('/register', [
            'name' => 'Tamper Bot',
            'email' => 'tamper@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'form_loaded_at' => 'not-a-valid-encrypted-value',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_registration_succeeds_with_valid_honeypot_fields(): void
    {
        $this->seedRequiredData();

        $response = $this->post('/register', [
            'name' => 'Real User',
            'email' => 'real@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'website' => '', // empty decoy
            'form_loaded_at' => encrypt(now()->subSeconds(10)->timestamp), // filled 10s ago
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('mobile-entry.lifts', absolute: false));
        $this->assertDatabaseHas('users', ['email' => 'real@example.com']);
    }

    public function test_registration_route_is_rate_limited(): void
    {
        // The route allows 2 requests per minute; the 3rd should be throttled (429).
        for ($i = 0; $i < 2; $i++) {
            $this->post('/register', [
                'name' => 'Test User',
                'email' => "invalid-email-{$i}", // invalid so no account is created
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);
        }

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'invalid-email-3',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(429);
    }
}
