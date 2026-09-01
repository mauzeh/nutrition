<?php

namespace Tests\Feature;

use App\Models\RehydrationSignal;
use App\Models\User;
use App\Sync\Services\RehydrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RehydrationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_raises_signal_for_multiple_users_and_returns_latest_token_and_reason(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $service = new RehydrationService();

        $this->assertNull($service->latestToken($user1));
        $this->assertNull($service->latestReason($user1));

        $service->raiseForUsers([$user1->id, $user2->id], 'exercise-merge');

        $token1 = $service->latestToken($user1);
        $token2 = $service->latestToken($user2);

        $this->assertNotNull($token1);
        $this->assertEquals($token1, $token2);
        $this->assertEquals('exercise-merge', $service->latestReason($user1));
        $this->assertEquals('exercise-merge', $service->latestReason($user2));
    }

    public function test_global_signal_is_returned_for_any_user(): void
    {
        $user = User::factory()->create();
        $service = new RehydrationService();

        RehydrationSignal::create([
            'user_id' => null,
            'token' => '2026-09-01T12:00:00Z',
            'reason' => 'global-reseed',
        ]);

        $this->assertEquals('2026-09-01T12:00:00Z', $service->latestToken($user));
        $this->assertEquals('global-reseed', $service->latestReason($user));
    }
}
