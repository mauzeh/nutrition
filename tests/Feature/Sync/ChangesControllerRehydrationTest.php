<?php

namespace Tests\Feature\Sync;

use App\Models\RehydrationSignal;
use App\Models\User;
use App\Sync\Services\RehydrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChangesControllerRehydrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_signal_has_no_rehydrate_key(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/sync/changes');

        $response->assertStatus(200)
            ->assertJsonMissingPath('rehydrate');
    }

    public function test_user_with_signal_emits_rehydrate_token_and_reason(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        app(RehydrationService::class)->raiseForUsers([$user->id], 'exercise-merge');

        $token = RehydrationSignal::where('user_id', $user->id)->value('token');

        $response = $this->getJson('/api/sync/changes');

        $response->assertStatus(200)
            ->assertJson([
                'rehydrate' => [
                    'token' => $token,
                    'reason' => 'exercise-merge',
                ],
            ]);
    }

    public function test_global_signal_reaches_user_without_personal_signal(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        RehydrationSignal::create([
            'user_id' => null,
            'token' => '2026-09-01T15:00:00Z',
            'reason' => 'global-reseed',
        ]);

        $response = $this->getJson('/api/sync/changes');

        $response->assertStatus(200)
            ->assertJson([
                'rehydrate' => [
                    'token' => '2026-09-01T15:00:00Z',
                    'reason' => 'global-reseed',
                ],
            ]);
    }
}
