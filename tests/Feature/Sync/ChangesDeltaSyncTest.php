<?php

namespace Tests\Feature\Sync;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\LiftSet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChangesDeltaSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_since_omitted_returns_full_dump_and_cursor(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $exercise = Exercise::factory()->create();

        $log1 = LiftLog::factory()->for($user)->for($exercise)->create();
        LiftSet::factory()->for($log1)->create(['unit' => 'lbs']);

        $log2 = LiftLog::factory()->for($user)->for($exercise)->create();
        LiftSet::factory()->for($log2)->create(['unit' => 'lbs']);

        $log3 = LiftLog::factory()->for($user)->for($exercise)->create();
        LiftSet::factory()->for($log3)->create(['unit' => 'lbs']);
        $log3->delete();

        $response = $this->getJson('/api/sync/changes');

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'cursor', 'logs', 'deleted_ids', 'userExercises']);

        $logs = $response->json('logs');
        $this->assertCount(2, $logs);

        $deletedIds = $response->json('deleted_ids');
        $this->assertCount(1, $deletedIds);
        $this->assertEquals($log3->id, $deletedIds[0]);

        $this->assertNotNull($response->json('cursor'));
    }

    public function test_since_filters_live_logs_and_tombstones(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $exercise = Exercise::factory()->create();

        $t1 = Carbon::parse('2026-09-01T10:00:00Z');
        $t2 = Carbon::parse('2026-09-01T12:00:00Z');
        $t3 = Carbon::parse('2026-09-01T14:00:00Z');

        $log1 = LiftLog::factory()->for($user)->for($exercise)->create();
        LiftSet::factory()->for($log1)->create(['unit' => 'lbs']);
        LiftLog::where('id', $log1->id)->update(['updated_at' => $t1]);

        $log2 = LiftLog::factory()->for($user)->for($exercise)->create();
        LiftSet::factory()->for($log2)->create(['unit' => 'lbs']);
        LiftLog::where('id', $log2->id)->update(['updated_at' => $t3]);

        $log3 = LiftLog::factory()->for($user)->for($exercise)->create();
        LiftSet::factory()->for($log3)->create(['unit' => 'lbs']);
        $log3->delete();
        LiftLog::withTrashed()->where('id', $log3->id)->update(['deleted_at' => $t1]);

        $log4 = LiftLog::factory()->for($user)->for($exercise)->create();
        LiftSet::factory()->for($log4)->create(['unit' => 'lbs']);
        $log4->delete();
        LiftLog::withTrashed()->where('id', $log4->id)->update(['deleted_at' => $t3]);

        $response = $this->getJson('/api/sync/changes?since=' . urlencode($t2->toIso8601String()));

        $response->assertStatus(200);

        $logs = $response->json('logs');
        $this->assertCount(1, $logs);
        $this->assertEquals($log2->id, $logs[0]['id']);

        $deletedIds = $response->json('deleted_ids');
        $this->assertCount(1, $deletedIds);
        $this->assertEquals($log4->id, $deletedIds[0]);
    }

    public function test_since_inclusive_boundary(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $exercise = Exercise::factory()->create();
        $t = Carbon::parse('2026-09-01T12:00:00Z');

        $log1 = LiftLog::factory()->for($user)->for($exercise)->create();
        LiftSet::factory()->for($log1)->create(['unit' => 'lbs']);
        LiftLog::where('id', $log1->id)->update(['updated_at' => $t]);

        $log2 = LiftLog::factory()->for($user)->for($exercise)->create();
        LiftSet::factory()->for($log2)->create(['unit' => 'lbs']);
        $log2->delete();
        LiftLog::withTrashed()->where('id', $log2->id)->update(['deleted_at' => $t]);

        $response = $this->getJson('/api/sync/changes?since=' . urlencode($t->toIso8601String()));

        $response->assertStatus(200);

        $logs = $response->json('logs');
        $this->assertCount(1, $logs);
        $this->assertEquals($log1->id, $logs[0]['id']);

        $deletedIds = $response->json('deleted_ids');
        $this->assertCount(1, $deletedIds);
        $this->assertEquals($log2->id, $deletedIds[0]);
    }

    public function test_cursor_computation(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $exercise = Exercise::factory()->create();

        $tz = config('app.timezone');
        $t1 = Carbon::parse('2026-09-01 10:00:00', $tz);
        $t2 = Carbon::parse('2026-09-01 15:00:00', $tz);

        $log1 = LiftLog::factory()->for($user)->for($exercise)->create();
        LiftSet::factory()->for($log1)->create(['unit' => 'lbs']);
        LiftLog::where('id', $log1->id)->update(['updated_at' => $t1]);

        $log2 = LiftLog::factory()->for($user)->for($exercise)->create();
        LiftSet::factory()->for($log2)->create(['unit' => 'lbs']);
        $log2->delete();
        LiftLog::withTrashed()->where('id', $log2->id)->update(['updated_at' => $t1, 'deleted_at' => $t2]);

        $response = $this->getJson('/api/sync/changes');
        $response->assertStatus(200);
        $this->assertEquals($t2->toIso8601String(), $response->json('cursor'));

        $tLater = Carbon::parse('2026-09-01 18:00:00', $tz);
        $response2 = $this->getJson('/api/sync/changes?since=' . urlencode($tLater->toIso8601String()));
        $response2->assertStatus(200);
        $this->assertEquals($tLater->toIso8601String(), $response2->json('cursor'));
    }

    public function test_old_tombstone_forever_fix(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $exercise = Exercise::factory()->create();

        $tOld = Carbon::parse('2026-09-01T08:00:00Z');
        $tSince = Carbon::parse('2026-09-01T12:00:00Z');
        $tNew = Carbon::parse('2026-09-01T14:00:00Z');

        $oldLog = LiftLog::factory()->for($user)->for($exercise)->create();
        LiftSet::factory()->for($oldLog)->create(['unit' => 'lbs']);
        $oldLog->delete();
        LiftLog::withTrashed()->where('id', $oldLog->id)->update(['deleted_at' => $tOld]);

        $newLog = LiftLog::factory()->for($user)->for($exercise)->create();
        LiftSet::factory()->for($newLog)->create(['unit' => 'lbs']);
        $newLog->delete();
        LiftLog::withTrashed()->where('id', $newLog->id)->update(['deleted_at' => $tNew]);

        $response = $this->getJson('/api/sync/changes?since=' . urlencode($tSince->toIso8601String()));

        $response->assertStatus(200);
        $deletedIds = $response->json('deleted_ids');
        $this->assertContains($newLog->id, $deletedIds);
        $this->assertNotContains($oldLog->id, $deletedIds);
    }
}
