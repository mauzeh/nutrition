<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrTypeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pr_type_varchar_migration_allows_novel_strings()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['user_id' => $user->id]);

        $liftLog = \App\Models\LiftLog::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercise->id]);

        $pr = PersonalRecord::create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'lift_log_id' => $liftLog->id,
            'pr_type' => 'novel_custom_pr_type_123',
            'value' => 100,
            'achieved_at' => now(),
        ]);

        $this->assertEquals('novel_custom_pr_type_123', $pr->fresh()->pr_type);
    }
}
