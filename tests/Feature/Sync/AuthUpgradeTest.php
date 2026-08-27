<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Sync\Services\AppleJwtVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthUpgradeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test successful email-based registration.
     */
    public function test_email_registration_success(): void
    {
        $response = $this->postJson('/api/sync/register', [
            'email' => 'athlete@example.com',
            'password' => 'secret123',
            'name' => 'Athlete Name',
            'device_id' => 'my-device-123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'token', 'athlete', 'email'])
            ->assertJson([
                'status' => 'ok',
                'athlete' => 'Athlete Name',
                'email' => 'athlete@example.com',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'athlete@example.com',
            'name' => 'Athlete Name',
        ]);

        $user = User::where('email', 'athlete@example.com')->first();
        $this->assertTrue(Hash::check('secret123', $user->password));
        $this->assertCount(1, $user->tokens);
        $this->assertEquals('my-device-123', $user->tokens->first()->name);
    }

    /**
     * Test registration with duplicate email.
     */
    public function test_email_registration_duplicate_email(): void
    {
        User::factory()->create(['email' => 'athlete@example.com']);

        $response = $this->postJson('/api/sync/register', [
            'email' => 'athlete@example.com',
            'password' => 'secret123',
            'name' => 'Another Name',
            'device_id' => 'device-456',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
            ]);
        $this->assertStringContainsString('email', $response->json('message'));
    }

    /**
     * Test registration validation errors.
     */
    public function test_email_registration_validation_errors(): void
    {
        // Missing all fields
        $response = $this->postJson('/api/sync/register', []);
        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
            ]);

        // Invalid email, short password
        $response = $this->postJson('/api/sync/register', [
            'email' => 'not-an-email',
            'password' => '123',
            'name' => '',
            'device_id' => '',
        ]);
        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
            ]);
    }

    /**
     * Test successful login.
     */
    public function test_email_login_success(): void
    {
        $user = User::factory()->create([
            'email' => 'athlete@example.com',
            'password' => Hash::make('secret123'),
            'name' => 'Athlete Name',
        ]);

        $response = $this->postJson('/api/sync/login', [
            'email' => 'athlete@example.com',
            'password' => 'secret123',
            'device_id' => 'my-device-123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'token', 'athlete', 'email'])
            ->assertJson([
                'status' => 'ok',
                'athlete' => 'Athlete Name',
                'email' => 'athlete@example.com',
            ]);

        $this->assertCount(1, $user->fresh()->tokens);
    }

    /**
     * Test login with wrong password.
     */
    public function test_email_login_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'athlete@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/sync/login', [
            'email' => 'athlete@example.com',
            'password' => 'wrongpassword',
            'device_id' => 'device-123',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Invalid credentials.',
            ]);
    }

    /**
     * Test login local environment bypass.
     */
    public function test_email_login_local_environment_bypass(): void
    {
        $user = User::factory()->create([
            'email' => 'athlete@example.com',
            'password' => Hash::make('secret123'),
            'name' => 'Athlete Name',
        ]);

        // Temporarily set the environment to local
        $this->app['env'] = 'local';

        // Any password should work now
        $response = $this->postJson('/api/sync/login', [
            'email' => 'athlete@example.com',
            'password' => 'any-random-password',
            'device_id' => 'device-123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'athlete' => 'Athlete Name',
                'email' => 'athlete@example.com',
            ]);
    }

    /**
     * Test login validation errors.
     */
    public function test_email_login_validation_errors(): void
    {
        $response = $this->postJson('/api/sync/login', []);
        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
            ]);
    }

    /**
     * Test Apple auth success.
     */
    public function test_apple_auth_success(): void
    {
        $this->mock(AppleJwtVerifier::class, function ($mock) {
            $mock->shouldReceive('verify')
                ->with('valid-apple-token')
                ->once()
                ->andReturn([
                    'email' => 'apple@example.com',
                    'name' => 'Apple User',
                    'sub' => 'apple-sub-123',
                ]);
        });

        $response = $this->postJson('/api/sync/auth/apple', [
            'identity_token' => 'valid-apple-token',
            'device_id' => 'device-apple',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'token', 'athlete', 'email'])
            ->assertJson([
                'status' => 'ok',
                'athlete' => 'Apple User',
                'email' => 'apple@example.com',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'apple@example.com',
            'name' => 'Apple User',
        ]);
    }

    /**
     * Test Apple auth name fallback when name is null in payload.
     */
    public function test_apple_auth_name_fallback(): void
    {
        $this->mock(AppleJwtVerifier::class, function ($mock) {
            $mock->shouldReceive('verify')
                ->with('valid-apple-token')
                ->once()
                ->andReturn([
                    'email' => 'apple_athlete@example.com',
                    'name' => null,
                    'sub' => 'apple-sub-123',
                ]);
        });

        $response = $this->postJson('/api/sync/auth/apple', [
            'identity_token' => 'valid-apple-token',
            'device_id' => 'device-apple',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'athlete' => 'apple_athlete', // name fallback to email prefix
                'email' => 'apple_athlete@example.com',
            ]);
    }

    /**
     * Test Apple auth invalid token.
     */
    public function test_apple_auth_invalid_token(): void
    {
        $this->mock(AppleJwtVerifier::class, function ($mock) {
            $mock->shouldReceive('verify')
                ->with('invalid-token')
                ->once()
                ->andThrow(new \UnexpectedValueException('Invalid token.'));
        });

        $response = $this->postJson('/api/sync/auth/apple', [
            'identity_token' => 'invalid-token',
            'device_id' => 'device-apple',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Invalid Apple token.',
            ]);
    }

    /**
     * Test email check endpoint.
     */
    public function test_email_check_nonexistent(): void
    {
        $response = $this->postJson('/api/sync/auth/check', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'next_step' => 'register',
            ])
            // The response must not expose a clean existence/method oracle.
            ->assertJsonMissingPath('exists')
            ->assertJsonMissingPath('has_password')
            ->assertJsonMissingPath('has_google');
    }

    /**
     * Test email check existing user.
     */
    public function test_email_check_existing(): void
    {
        // 1. Existing user with password only
        $user1 = User::factory()->create([
            'email' => 'user1@example.com',
            'password' => Hash::make('secret123'),
            'google_id' => null,
        ]);

        $response = $this->postJson('/api/sync/auth/check', [
            'email' => 'user1@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'next_step' => 'password',
            ]);

        // 2. Existing user with Google only
        $user2 = User::factory()->create([
            'email' => 'user2@example.com',
            'password' => Hash::make('social-auth-placeholder-value'),
            'google_id' => 'google-sub-456',
        ]);

        $response = $this->postJson('/api/sync/auth/check', [
            'email' => 'user2@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'next_step' => 'google',
            ]);
    }
}
