<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_generate_connection_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->post(route('connections.generate-token'));

        $response->assertRedirect(route('connections.index'));
        
        $user->refresh();
        $this->assertNotNull($user->connection_token);
        $this->assertNotNull($user->connection_token_expires_at);
        $this->assertEquals(6, strlen($user->connection_token));
    }

    public function test_connection_token_is_displayed_on_connections_page(): void
    {
        $user = User::factory()->create();
        $user->generateConnectionToken();

        $this->actingAs($user);

        $response = $this->get(route('connections.index'));

        $response->assertStatus(200);
        $response->assertSee('Connect');
        $response->assertSee($user->connection_token);
    }

    public function test_user_can_connect_via_valid_token(): void
    {
        $user1 = User::factory()->create(['name' => 'Alice']);
        $user2 = User::factory()->create(['name' => 'Bob']);

        $token = $user1->generateConnectionToken();

        $this->actingAs($user2);

        $response = $this->post(route('connections.connect', ['token' => $token]));

        $response->assertRedirect(route('connections.index'));
        $response->assertSessionHas('success');
        
        // Check mutual follow
        $this->assertTrue($user2->isFollowing($user1));
        $this->assertTrue($user1->isFollowing($user2));
    }

    public function test_cannot_connect_with_invalid_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->post(route('connections.connect', ['token' => '999999']));

        $response->assertRedirect(route('connections.index'));
        $response->assertSessionHas('error', 'Invalid or expired connection code.');
    }

    public function test_cannot_connect_with_expired_token(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $token = $user1->generateConnectionToken();
        
        // Manually expire the token
        $user1->update(['connection_token_expires_at' => now()->subMinutes(1)]);

        $this->actingAs($user2);

        $response = $this->post(route('connections.connect', ['token' => $token]));

        $response->assertRedirect(route('connections.index'));
        $response->assertSessionHas('error', 'Invalid or expired connection code.');
    }

    public function test_cannot_connect_with_self(): void
    {
        $user = User::factory()->create();
        $token = $user->generateConnectionToken();

        $this->actingAs($user);

        $response = $this->post(route('connections.connect', ['token' => $token]));

        $response->assertRedirect(route('connections.index'));
        $response->assertSessionHas('error', 'You cannot connect with yourself.');
    }

    public function test_get_valid_connection_token_returns_existing_valid_token(): void
    {
        $user = User::factory()->create();
        
        $token1 = $user->getValidConnectionToken();
        $token2 = $user->getValidConnectionToken();

        $this->assertEquals($token1, $token2);
    }

    public function test_get_valid_connection_token_generates_new_token_when_expired(): void
    {
        $user = User::factory()->create();
        
        $token1 = $user->generateConnectionToken();
        
        // Expire the token
        $user->update(['connection_token_expires_at' => now()->subMinutes(1)]);
        
        $token2 = $user->getValidConnectionToken();

        $this->assertNotEquals($token1, $token2);
    }

    public function test_connection_creates_mutual_follow(): void
    {
        $user1 = User::factory()->create(['name' => 'Alice']);
        $user2 = User::factory()->create(['name' => 'Bob']);

        $token = $user1->generateConnectionToken();

        $this->actingAs($user2);
        $this->post(route('connections.connect', ['token' => $token]));

        // Both users should follow each other
        $this->assertTrue($user1->isFollowing($user2));
        $this->assertTrue($user2->isFollowing($user1));
        
        // Check counts
        $this->assertEquals(1, $user1->following()->count());
        $this->assertEquals(1, $user1->followers()->count());
        $this->assertEquals(1, $user2->following()->count());
        $this->assertEquals(1, $user2->followers()->count());
    }

    public function test_connecting_twice_does_not_create_duplicates(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $token = $user1->generateConnectionToken();

        $this->actingAs($user2);
        $this->post(route('connections.connect', ['token' => $token]));
        
        // Generate new token and connect again
        $token2 = $user1->generateConnectionToken();
        $this->post(route('connections.connect', ['token' => $token2]));

        // Should still only have 1 follow relationship each way
        $this->assertEquals(1, $user1->following()->count());
        $this->assertEquals(1, $user2->following()->count());
    }

    public function test_new_connection_prs_show_as_unread_in_feed(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        
        // User1 creates a PR with show_in_feed enabled
        $exercise = \App\Models\Exercise::factory()->create([
            'user_id' => $user1->id,
            'show_in_feed' => true,
        ]);
        $liftLog = \App\Models\LiftLog::factory()->create([
            'user_id' => $user1->id,
            'exercise_id' => $exercise->id,
            'logged_at' => now()->subDays(2),
        ]);
        $pr = \App\Models\PersonalRecord::factory()->create([
            'user_id' => $user1->id,
            'exercise_id' => $exercise->id,
            'lift_log_id' => $liftLog->id,
            'achieved_at' => now()->subDays(2),
        ]);
        
        // User2 connects with User1 via token (before viewing feed)
        $this->actingAs($user2);
        $token = $user1->generateConnectionToken();
        $this->post(route('connections.connect', ['token' => $token]));
        
        // Verify User1's PR is not marked as read by User2
        $this->assertFalse($pr->isReadBy($user2));
        
        // When User2 views feed, User1's PR should be visible
        $response = $this->get(route('feed.index'));
        
        // Check that the feed shows the PR
        $response->assertSee($exercise->title);
        
        // After viewing, the PR should be marked as read
        $user2->refresh();
        $this->assertTrue($pr->isReadBy($user2));
    }

    public function test_user_can_connect_via_get_request_with_valid_token(): void
    {
        $user1 = User::factory()->create(['name' => 'Alice']);
        $user2 = User::factory()->create(['name' => 'Bob']);

        $token = $user1->generateConnectionToken();

        $this->actingAs($user2);

        // Test GET request (for QR code scanning)
        $response = $this->get(route('connections.connect.get', ['token' => $token]));

        $response->assertRedirect(route('connections.index'));
        $response->assertSessionHas('success');
        
        // Check mutual follow
        $this->assertTrue($user2->isFollowing($user1));
        $this->assertTrue($user1->isFollowing($user2));
    }

    public function test_get_request_cannot_connect_with_invalid_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->get(route('connections.connect.get', ['token' => '999999']));

        $response->assertRedirect(route('connections.index'));
        $response->assertSessionHas('error', 'Invalid or expired connection code.');
    }

    public function test_get_request_cannot_connect_with_expired_token(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $token = $user1->generateConnectionToken();
        
        // Manually expire the token
        $user1->update(['connection_token_expires_at' => now()->subMinutes(1)]);

        $this->actingAs($user2);

        $response = $this->get(route('connections.connect.get', ['token' => $token]));

        $response->assertRedirect(route('connections.index'));
        $response->assertSessionHas('error', 'Invalid or expired connection code.');
    }

    public function test_get_request_cannot_connect_with_self(): void
    {
        $user = User::factory()->create();
        $token = $user->generateConnectionToken();

        $this->actingAs($user);

        $response = $this->get(route('connections.connect.get', ['token' => $token]));

        $response->assertRedirect(route('connections.index'));
        $response->assertSessionHas('error', 'You cannot connect with yourself.');
    }

    public function test_qr_code_url_works_with_get_request(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $token = $user1->generateConnectionToken();
        
        // Simulate scanning QR code (GET request)
        $this->actingAs($user2);
        $response = $this->get("/connections/connect/{$token}");

        $response->assertRedirect(route('connections.index'));
        $response->assertSessionHas('success');
        
        // Verify connection was established
        $this->assertTrue($user2->isFollowing($user1));
        $this->assertTrue($user1->isFollowing($user2));
    }

    public function test_connection_attempts_are_rate_limited(): void
    {
        $attacker = User::factory()->create();
        $this->actingAs($attacker);

        // The limiter allows 10 attempts per minute; the 11th must be throttled,
        // making the 6-digit code space infeasible to brute-force.
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('connections.connect', ['token' => '000000']))
                ->assertRedirect(route('connections.index'));
        }

        $this->post(route('connections.connect', ['token' => '000000']))
            ->assertStatus(429);
    }
}
