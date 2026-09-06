<?php

namespace Tests\Feature\Sync;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoadOutputGroupRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_load_output_set_when_both_distance_and_duration_are_null(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-device')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        $invalidLog = [
            'exercise_name' => 'Invalid Carry',
            'canonical_name' => 'invalid_carry',
            'date' => '2026-09-06',
            'log_type' => 'weighted-carry',
            'weight_unit' => 'lbs',
            'sets' => [
                ['weight' => 50], // Weight only, no distance or duration
            ],
        ];

        $response = $this->withHeaders($headers)->postJson('/api/sync/logs', $invalidLog);
        $response->assertStatus(422);
    }

    public function test_accepts_load_output_set_with_distance_or_duration(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-device')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        $validDistLog = [
            'exercise_name' => 'Farmer Carry Dist',
            'canonical_name' => 'farmer_carry_dist',
            'date' => '2026-09-06',
            'log_type' => 'weighted-carry',
            'weight_unit' => 'lbs',
            'sets' => [
                ['weight' => 50, 'distance' => 100],
            ],
        ];

        $responseDist = $this->withHeaders($headers)->postJson('/api/sync/logs', $validDistLog);
        $responseDist->assertStatus(200);

        $validDurLog = [
            'exercise_name' => 'Farmer Carry Dur',
            'canonical_name' => 'farmer_carry_dur',
            'date' => '2026-09-06',
            'log_type' => 'weighted-carry',
            'weight_unit' => 'lbs',
            'sets' => [
                ['weight' => 50, 'duration' => 45],
            ],
        ];

        $responseDur = $this->withHeaders($headers)->postJson('/api/sync/logs', $validDurLog);
        $responseDur->assertStatus(200);
    }

    public function test_web_create_and_update_load_output_validation(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create([
            'exercise_type' => 'load_output',
            'log_type' => 'weighted-carry',
        ]);

        // Rejects missing distance and time
        $responseAllNull = $this->actingAs($user)->post('/lift-logs', [
            'exercise_id' => $exercise->id,
            'rounds' => 1,
            'weight' => 50,
            'date' => '2026-09-06',
        ]);
        $responseAllNull->assertSessionHasErrors(['distance', 'time']);

        // Accepts distance only
        $responseDistOnly = $this->actingAs($user)->post('/lift-logs', [
            'exercise_id' => $exercise->id,
            'rounds' => 1,
            'weight' => 50,
            'distance' => 100,
            'date' => '2026-09-06',
        ]);
        $responseDistOnly->assertSessionHasNoErrors();

        // Accepts time only
        $responseTimeOnly = $this->actingAs($user)->post('/lift-logs', [
            'exercise_id' => $exercise->id,
            'rounds' => 1,
            'weight' => 50,
            'time' => 30,
            'date' => '2026-09-06',
        ]);
        $responseTimeOnly->assertSessionHasNoErrors();
    }
}
