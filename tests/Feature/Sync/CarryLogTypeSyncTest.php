<?php

namespace Tests\Feature\Sync;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarryLogTypeSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_carry_log_types_creates_load_output_and_records_prs(): void
    {
        $user = User::factory()->create(['name' => 'carry_tester']);
        $token = $user->createToken('test-device')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        // 1. Post weighted-carry-2-kb (kbWeight + distance + duration)
        $kbLog = [
            'exercise_name' => 'Double KB Farmers Carry',
            'canonical_name' => 'double_kb_farmers_carry',
            'date' => '2026-09-04',
            'log_type' => 'weighted-carry-2-kb',
            'weight_unit' => 'lbs',
            'sets' => [
                ['kbWeight' => 53, 'distance' => 100, 'distanceUnit' => 'm', 'duration' => 45],
            ],
        ];

        $res1 = $this->withHeaders($headers)->postJson('/api/sync/logs', $kbLog);
        $res1->assertStatus(200)->assertJson(['status' => 'ok']);

        $ex1 = Exercise::where('canonical_name', 'double_kb_farmers_carry')->first();
        $this->assertNotNull($ex1);
        $this->assertEquals('load_output', $ex1->exercise_type);
        $this->assertEquals('weighted-carry-2-kb', $ex1->log_type);

        $prs1 = PersonalRecord::where('exercise_id', $ex1->id)->where('user_id', $user->id)->get();
        $this->assertNotEmpty($prs1);
        $prTypes1 = $prs1->pluck('pr_type')->toArray();
        $this->assertContains('load', $prTypes1);
        $this->assertContains('distance', $prTypes1);
        $this->assertContains('duration', $prTypes1);

        // 2. Post weighted-carry-1-db (weight + distance)
        $dbLog = [
            'exercise_name' => 'Single DB Suitcase Carry',
            'canonical_name' => 'single_db_suitcase_carry',
            'date' => '2026-09-04',
            'log_type' => 'weighted-carry-1-db',
            'weight_unit' => 'lbs',
            'sets' => [
                ['weight' => 60, 'distance' => 50, 'distanceUnit' => 'ft'],
            ],
        ];

        $res2 = $this->withHeaders($headers)->postJson('/api/sync/logs', $dbLog);
        $res2->assertStatus(200)->assertJson(['status' => 'ok']);

        $ex2 = Exercise::where('canonical_name', 'single_db_suitcase_carry')->first();
        $this->assertNotNull($ex2);
        $this->assertEquals('load_output', $ex2->exercise_type);
        $this->assertEquals('weighted-carry-1-db', $ex2->log_type);

        $prs2 = PersonalRecord::where('exercise_id', $ex2->id)->where('user_id', $user->id)->get();
        $this->assertNotEmpty($prs2);
        $prTypes2 = $prs2->pluck('pr_type')->toArray();
        $this->assertContains('load', $prTypes2);
        $this->assertContains('distance', $prTypes2);

        // 3. Post weighted-carry-ball (ballWeight + duration)
        $ballLog = [
            'exercise_name' => 'Medicine Ball Bear Hug Carry',
            'canonical_name' => 'medicine_ball_bear_hug_carry',
            'date' => '2026-09-04',
            'log_type' => 'weighted-carry-ball',
            'weight_unit' => 'lbs',
            'sets' => [
                ['ballWeight' => 70, 'duration' => 60],
            ],
        ];

        $res3 = $this->withHeaders($headers)->postJson('/api/sync/logs', $ballLog);
        $res3->assertStatus(200)->assertJson(['status' => 'ok']);

        $ex3 = Exercise::where('canonical_name', 'medicine_ball_bear_hug_carry')->first();
        $this->assertNotNull($ex3);
        $this->assertEquals('load_output', $ex3->exercise_type);
        $this->assertEquals('weighted-carry-ball', $ex3->log_type);

        $prs3 = PersonalRecord::where('exercise_id', $ex3->id)->where('user_id', $user->id)->get();
        $this->assertNotEmpty($prs3);
        $prTypes3 = $prs3->pluck('pr_type')->toArray();
        $this->assertContains('load', $prTypes3);
        $this->assertContains('duration', $prTypes3);

        // 4. GET /api/sync/restore returns generic weight, distance, distance_unit, duration
        $restoreRes = $this->withHeaders($headers)->getJson('/api/sync/restore');
        $restoreRes->assertStatus(200)->assertJson(['status' => 'ok']);

        $logs = $restoreRes->json('logs');
        $this->assertCount(3, $logs);

        $kbRestored = collect($logs)->firstWhere('exerciseId', 'double_kb_farmers_carry');
        $this->assertNotNull($kbRestored);
        $this->assertEquals('weighted-carry-2-kb', $kbRestored['logType']);
        $this->assertEquals(53, $kbRestored['sets'][0]['weight']);
        $this->assertEquals(100, $kbRestored['sets'][0]['distance']);
        $this->assertEquals('m', $kbRestored['sets'][0]['distance_unit']);
        $this->assertEquals(45, $kbRestored['sets'][0]['duration']);
    }
}
