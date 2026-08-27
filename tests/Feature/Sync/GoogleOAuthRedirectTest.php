<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleOAuthRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─── googleRedirect ───────────────────────────────────────────

    public function test_google_redirect_returns_redirect_to_google(): void
    {
        Socialite::shouldReceive('driver')
            ->with('google')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('stateless')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('redirectUrl')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('with')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('redirect')
            ->once()
            ->andReturn(redirect('https://accounts.google.com/o/oauth2/auth?client_id=test'));

        $response = $this->get('/api/sync/auth/google/redirect?device_id=test-device');

        $response->assertRedirect();
        $response->assertRedirectContains('accounts.google.com');
    }

    public function test_google_redirect_defaults_device_id_when_not_provided(): void
    {
        Socialite::shouldReceive('driver')
            ->with('google')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('stateless')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('redirectUrl')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('with')
            ->once()
            ->withArgs(function ($args) {
                $state = json_decode(base64_decode($args['state']), true);

                return $state['device_id'] === 'athlete-pwa'
                    && $args['prompt'] === 'select_account';
            })
            ->andReturnSelf();

        Socialite::shouldReceive('redirect')
            ->once()
            ->andReturn(redirect('https://accounts.google.com'));

        $response = $this->get('/api/sync/auth/google/redirect');

        $response->assertRedirect();
    }

    public function test_google_redirect_encodes_device_id_and_athlete_url_in_state(): void
    {
        Socialite::shouldReceive('driver')
            ->with('google')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('stateless')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('redirectUrl')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('with')
            ->once()
            ->withArgs(function ($args) {
                $state = json_decode(base64_decode($args['state']), true);

                return $state['device_id'] === 'my-device-42'
                    && isset($state['athlete_url']);
            })
            ->andReturnSelf();

        Socialite::shouldReceive('redirect')
            ->once()
            ->andReturn(redirect('https://accounts.google.com'));

        $response = $this->get('/api/sync/auth/google/redirect?device_id=my-device-42');

        $response->assertRedirect();
    }

    // ─── googleCallback — success ─────────────────────────────────

    public function test_google_callback_creates_user_and_redirects_with_token(): void
    {
        $googleUser = Mockery::mock(SocialiteUser::class);
        $googleUser->shouldReceive('getEmail')->andReturn('athlete@example.com');
        $googleUser->shouldReceive('getName')->andReturn('Test Athlete');
        $googleUser->shouldReceive('getId')->andReturn('google-sub-789');

        Socialite::shouldReceive('driver')
            ->with('google')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('stateless')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('redirectUrl')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('user')
            ->once()
            ->andReturn($googleUser);

        $state = base64_encode(json_encode([
            'device_id' => 'test-device',
            'athlete_url' => 'https://squirby.app',
        ]));

        $response = $this->get('/api/sync/auth/google/callback?state=' . $state . '&code=mock-code');

        $response->assertRedirect();
        $redirectUrl = $response->headers->get('Location');

        // Verify redirect goes to the athlete app callback
        $this->assertStringStartsWith('https://squirby.app/auth/callback?', $redirectUrl);

        // Verify all required params are present
        $query = parse_url($redirectUrl, PHP_URL_QUERY);
        parse_str($query, $params);
        $this->assertArrayHasKey('token', $params);
        $this->assertArrayHasKey('athlete', $params);
        $this->assertArrayHasKey('email', $params);
        $this->assertEquals('Test Athlete', $params['athlete']);
        $this->assertEquals('athlete@example.com', $params['email']);
        $this->assertNotEmpty($params['token']);

        // Verify user was created
        $this->assertDatabaseHas('users', [
            'email' => 'athlete@example.com',
            'name' => 'Test Athlete',
            'google_id' => 'google-sub-789',
        ]);
    }

    public function test_google_callback_existing_user_links_google_id(): void
    {
        $user = User::factory()->create([
            'email' => 'existing@example.com',
            'name' => 'Existing Athlete',
            'google_id' => null,
        ]);

        $googleUser = Mockery::mock(SocialiteUser::class);
        $googleUser->shouldReceive('getEmail')->andReturn('existing@example.com');
        $googleUser->shouldReceive('getName')->andReturn('Google Name');
        $googleUser->shouldReceive('getId')->andReturn('google-sub-456');

        Socialite::shouldReceive('driver')
            ->with('google')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('stateless')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('redirectUrl')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('user')
            ->once()
            ->andReturn($googleUser);

        $state = base64_encode(json_encode([
            'device_id' => 'test-device',
            'athlete_url' => 'https://squirby.app',
        ]));

        $response = $this->get('/api/sync/auth/google/callback?state=' . $state . '&code=mock-code');

        $response->assertRedirect();

        // Verify google_id was linked
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'google_id' => 'google-sub-456',
            'name' => 'Existing Athlete', // Name should not be overwritten
        ]);
    }

    // ─── googleCallback — error ───────────────────────────────────

    public function test_google_callback_redirects_with_error_on_socialite_failure(): void
    {
        Socialite::shouldReceive('driver')
            ->with('google')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('stateless')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('redirectUrl')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('user')
            ->once()
            ->andThrow(new \Exception('Token exchange failed'));

        $state = base64_encode(json_encode([
            'device_id' => 'test-device',
            'athlete_url' => 'https://squirby.app',
        ]));

        $response = $this->get('/api/sync/auth/google/callback?state=' . $state . '&code=bad-code');

        $response->assertRedirect('https://squirby.app/auth/callback?error=google_auth_failed');
    }

    public function test_google_callback_rejects_attacker_controlled_redirect_host(): void
    {
        $googleUser = Mockery::mock(SocialiteUser::class);
        $googleUser->shouldReceive('getEmail')->andReturn('victim@example.com');
        $googleUser->shouldReceive('getName')->andReturn('Victim');
        $googleUser->shouldReceive('getId')->andReturn('google-sub-evil');

        Socialite::shouldReceive('driver')->with('google')->once()->andReturnSelf();
        Socialite::shouldReceive('stateless')->once()->andReturnSelf();
        Socialite::shouldReceive('redirectUrl')->once()->andReturnSelf();
        Socialite::shouldReceive('user')->once()->andReturn($googleUser);

        // Attacker crafts the (unsigned) state to point the token at their host.
        $state = base64_encode(json_encode([
            'device_id' => 'test-device',
            'athlete_url' => 'https://evil.example.com',
        ]));

        $response = $this->get('/api/sync/auth/google/callback?state=' . $state . '&code=mock-code');

        $response->assertRedirect();
        $redirectUrl = $response->headers->get('Location');

        // The token must NOT be delivered to the attacker host; it falls back to
        // the configured athlete URL instead.
        $this->assertStringNotContainsString('evil.example.com', $redirectUrl);
        $this->assertStringStartsWith(
            config('services.athlete.url') . '/auth/callback?',
            $redirectUrl
        );
    }

    public function test_google_callback_uses_default_athlete_url_when_state_missing(): void
    {
        Socialite::shouldReceive('driver')
            ->with('google')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('stateless')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('redirectUrl')
            ->once()
            ->andReturnSelf();

        Socialite::shouldReceive('user')
            ->once()
            ->andThrow(new \Exception('No state'));

        $response = $this->get('/api/sync/auth/google/callback?code=bad-code');

        $response->assertRedirect();
        $redirectUrl = $response->headers->get('Location');

        // Should fall back to config default and include error param
        $this->assertStringContainsString('/auth/callback?error=google_auth_failed', $redirectUrl);
    }
}
