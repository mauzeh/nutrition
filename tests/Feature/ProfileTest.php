<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\Hash;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertSoftDeleted($user);
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }

    /** @test */
    public function test_password_can_be_updated_and_shows_success_message(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        // Follow the redirect and assert the success message on the page
        $response = $this->actingAs($user)->get('/profile'); // Re-fetch the profile page after redirect
        $response->assertSee('Password updated successfully.'); // Assert that the success message is visible
    }

    /** @test */
    public function test_password_update_shows_validation_errors(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ]);

        $response
            ->assertSessionHasErrorsIn('updatePassword', ['password'])
            ->assertRedirect('/profile');

        // Follow the redirect and assert the error message on the page
        $response = $this->actingAs($user)->get('/profile'); // Re-fetch the profile page after redirect
        $response->assertSee('The password field must be at least 8 characters.');
    }

    /** @test */
    public function test_profile_information_can_be_updated_and_shows_success_message(): void
    {
        $user = User::factory()->create();

        // Keep the same email so email verification is not reset. Changing the
        // email nulls email_verified_at, which (with the 'verified' middleware
        // now enforced) would redirect /profile to the verification notice
        // instead of showing the success flash. That re-verification behavior
        // is covered by test_updating_email_requires_reverification below.
        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Updated Name',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        // Follow the redirect and assert the success message on the page
        $response = $this->actingAs($user)->get('/profile');
        $response->assertSee('Profile information updated successfully.');
    }

    /** @test */
    public function test_updating_email_requires_reverification(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/profile', [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ])->assertRedirect('/profile');

        // Email change resets verification status...
        $this->assertNull($user->refresh()->email_verified_at);

        // ...and the now-unverified user is bounced to the verification notice.
        $this->actingAs($user)->get('/profile')
            ->assertRedirect(route('verification.notice'));
    }

    /** @test */
    public function test_profile_information_update_shows_validation_errors(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => '', // Invalid name
                'email' => 'invalid-email', // Invalid email
            ]);

        $response
            ->assertSessionHasErrors(['name', 'email'])
            ->assertRedirect('/profile');

        // Follow the redirect and assert the error messages on the page
        $response = $this->actingAs($user)->get('/profile');
        $response->assertSee('The name field is required.');
        $response->assertSee('The email field must be a valid email address.');
    }

    /** @test */
    public function test_show_global_exercises_field_accepts_valid_boolean_values(): void
    {
        $user = User::factory()->create();

        // Test with true
        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'show_global_exercises' => true,
            ]);

        $response->assertSessionHasNoErrors();

        // Test with false
        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'show_global_exercises' => false,
            ]);

        $response->assertSessionHasNoErrors();

        // Test with null (should be allowed)
        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'show_global_exercises' => null,
            ]);

        $response->assertSessionHasNoErrors();

        // Test without the field (backward compatibility)
        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => $user->email,
            ]);

        $response->assertSessionHasNoErrors();
    }

    /** @test */
    public function test_user_can_save_weight_unit_preference(): void
    {
        $user = User::factory()->create(['weight_unit' => 'lbs']);

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update-preferences'), [
                'weight_unit' => 'kg',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit-preferences'));

        $this->assertEquals('kg', $user->fresh()->weight_unit);
    }

    /** @test */
    public function test_user_cannot_save_invalid_weight_unit_preference(): void
    {
        $user = User::factory()->create(['weight_unit' => 'lbs']);

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit-preferences'))
            ->patch(route('profile.update-preferences'), [
                'weight_unit' => 'invalid_unit',
            ]);

        $response
            ->assertSessionHasErrors(['weight_unit'])
            ->assertRedirect(route('profile.edit-preferences'));

        $this->assertEquals('lbs', $user->fresh()->weight_unit);
    }
}
