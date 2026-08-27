<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36',
        ])->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('mobile-entry.lifts', absolute: false));
    }

    public function test_mobile_users_are_redirected_to_mobile_entry_after_login(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 13_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.1.1 Mobile/15E148 Safari/604.1',
        ])->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('mobile-entry.lifts', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_login_is_rate_limited_per_minute(): void
    {
        // The burst limit allows 2 attempts/min for a given email+IP; the 3rd
        // is throttled regardless of credential validity.
        for ($i = 0; $i < 2; $i++) {
            $this->post('/login', [
                'email' => 'target@example.com',
                'password' => 'wrong-password',
            ]);
        }

        $this->post('/login', [
            'email' => 'target@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_login_is_rate_limited_per_hour(): void
    {
        // Space attempts 90s apart: under the 2/min burst cap, and far enough
        // apart that Breeze's own 5-failure limiter (60s decay) never trips,
        // yet all 21 attempts fall within one hour so the 20/hour cap trips.
        for ($i = 0; $i < 20; $i++) {
            $this->travel(90)->seconds();
            $this->post('/login', [
                'email' => 'slow@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(302); // failed login redirect, not throttled
        }

        $this->travel(90)->seconds();
        $this->post('/login', [
            'email' => 'slow@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_login_rate_limit_is_scoped_per_email(): void
    {
        // Exhaust the per-minute burst for one email.
        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', [
                'email' => 'victim@example.com',
                'password' => 'wrong-password',
            ]);
        }

        // A different email from the same IP must not be locked out.
        $this->post('/login', [
            'email' => 'someone-else@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(302);
    }
}
