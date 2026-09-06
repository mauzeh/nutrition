<?php

namespace Tests\Feature\Sync;

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimedRepsSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_timed_reps_logs_creates_timed_output_and_records_prs(): void
    {
        $user = User::factory()->create(['name' => 'timed_reps_tester']);
        $token = $user->createToken('test-device')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        // 1. Post set (a): {duration: 40, reps: 12}
        $logBoth = [
            'exercise_name' => 'Glute Bridge March Both',
            'canonical_name' => 'glute_bridge_march_both',
            'date' => '2026-09-06',
            'log_type' => 'timed-reps',
            'weight_unit' => 'lbs',
            'sets' => [
                ['duration' => 40, 'reps' => 12],
            ],
        ];

        $resBoth = $this->withHeaders($headers)->postJson('/api/sync/logs', $logBoth);
        $resBoth->assertStatus(200)->assertJson(['status' => 'ok']);

        $exBoth = Exercise::where('canonical_name', 'glute_bridge_march_both')->first();
        $this->assertNotNull($exBoth);
        $this->assertEquals('timed_output', $exBoth->exercise_type);
        $this->assertEquals('timed-reps', $exBoth->log_type);

        $prsBoth = PersonalRecord::where('exercise_id', $exBoth->id)->where('user_id', $user->id)->pluck('pr_type')->toArray();
        $this->assertContains('time', $prsBoth);
        $this->assertContains('volume', $prsBoth);
        $this->assertContains('max_reps', $prsBoth);
        $this->assertContains('bodyweight_volume', $prsBoth);

        // 2. Post set (b): {reps: 12} (duration null)
        $logReps = [
            'exercise_name' => 'Glute Bridge March Reps',
            'canonical_name' => 'glute_bridge_march_reps',
            'date' => '2026-09-06',
            'log_type' => 'timed-reps',
            'weight_unit' => 'lbs',
            'sets' => [
                ['reps' => 12],
            ],
        ];

        $resReps = $this->withHeaders($headers)->postJson('/api/sync/logs', $logReps);
        $resReps->assertStatus(200)->assertJson(['status' => 'ok']);

        $exReps = Exercise::where('canonical_name', 'glute_bridge_march_reps')->first();
        $this->assertNotNull($exReps);
        $this->assertEquals('timed_output', $exReps->exercise_type);

        $prsReps = PersonalRecord::where('exercise_id', $exReps->id)->where('user_id', $user->id)->pluck('pr_type')->toArray();
        $this->assertNotContains('time', $prsReps);
        $this->assertContains('max_reps', $prsReps);
        $this->assertContains('bodyweight_volume', $prsReps);

        // 3. Post set (c): {duration: 40} (reps null)
        $logDur = [
            'exercise_name' => 'Glute Bridge March Dur',
            'canonical_name' => 'glute_bridge_march_dur',
            'date' => '2026-09-06',
            'log_type' => 'timed-reps',
            'weight_unit' => 'lbs',
            'sets' => [
                ['duration' => 40],
            ],
        ];

        $resDur = $this->withHeaders($headers)->postJson('/api/sync/logs', $logDur);
        $resDur->assertStatus(200)->assertJson(['status' => 'ok']);

        $exDur = Exercise::where('canonical_name', 'glute_bridge_march_dur')->first();
        $this->assertNotNull($exDur);
        $this->assertEquals('timed_output', $exDur->exercise_type);

        $prsDur = PersonalRecord::where('exercise_id', $exDur->id)->where('user_id', $user->id)->pluck('pr_type')->toArray();
        $this->assertContains('time', $prsDur);
        $this->assertContains('volume', $prsDur);
        $this->assertNotContains('max_reps', $prsDur);

        // 4. GET /api/sync/restore returns duration + reps with nulls intact
        $restoreRes = $this->withHeaders($headers)->getJson('/api/sync/restore');
        $restoreRes->assertStatus(200)->assertJson(['status' => 'ok']);
        $logs = $restoreRes->json('logs');

        $bothRestored = collect($logs)->firstWhere('exerciseId', 'glute_bridge_march_both');
        $this->assertEquals(40, $bothRestored['sets'][0]['duration']);
        $this->assertEquals(12, $bothRestored['sets'][0]['reps']);

        $repsRestored = collect($logs)->firstWhere('exerciseId', 'glute_bridge_march_reps');
        $this->assertNull($repsRestored['sets'][0]['duration']);
        $this->assertEquals(12, $repsRestored['sets'][0]['reps']);

        $durRestored = collect($logs)->firstWhere('exerciseId', 'glute_bridge_march_dur');
        $this->assertEquals(40, $durRestored['sets'][0]['duration']);
        $this->assertNull($durRestored['sets'][0]['reps']);
    }

    public function test_rejects_timed_reps_set_when_both_duration_and_reps_are_null(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-device')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        $invalidLog = [
            'exercise_name' => 'Invalid Timed Reps',
            'canonical_name' => 'invalid_timed_reps',
            'date' => '2026-09-06',
            'log_type' => 'timed-reps',
            'weight_unit' => 'lbs',
            'sets' => [
                [], // Both duration and reps null
            ],
        ];

        $response = $this->withHeaders($headers)->postJson('/api/sync/logs', $invalidLog);
        $response->assertStatus(422);
    }
}
