<?php

namespace Tests\Feature\Sync;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\LiftSet;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarryLogTypeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_retypes_creates_splits_and_purges_history(): void
    {
        $user = User::factory()->create();

        // 1. Existing carry exercise (non-split)
        $farmersEx = Exercise::create([
            'title' => "Farmer's Carry",
            'canonical_name' => 'farmers_carry',
            'log_type' => 'weighted-carry',
            'exercise_type' => 'static_hold',
        ]);

        $farmersLog = LiftLog::create([
            'user_id' => $user->id,
            'exercise_id' => $farmersEx->id,
            'log_type' => 'weighted-carry',
            'logged_at' => now(),
        ]);

        $farmersSet = LiftSet::create([
            'lift_log_id' => $farmersLog->id,
            'weight' => 50,
            'unit' => 'lbs',
            'time' => 30,
        ]);

        $farmersPr = PersonalRecord::create([
            'user_id' => $user->id,
            'exercise_id' => $farmersEx->id,
            'lift_log_id' => $farmersLog->id,
            'pr_type' => 'time',
            'value' => 30,
            'achieved_at' => now(),
        ]);

        // 2. Retired original split exercise
        $retiredEx = Exercise::create([
            'title' => 'Mixed Rack Carry',
            'canonical_name' => 'mixed_rack_carry',
            'log_type' => 'weighted-carry',
            'exercise_type' => 'static_hold',
        ]);

        $retiredLog = LiftLog::create([
            'user_id' => $user->id,
            'exercise_id' => $retiredEx->id,
            'log_type' => 'weighted-carry',
            'logged_at' => now(),
        ]);

        $retiredSet = LiftSet::create([
            'lift_log_id' => $retiredLog->id,
            'weight' => 40,
            'unit' => 'lbs',
            'time' => 20,
        ]);

        // 3. Genuine static hold (must be UNTOUCHED)
        $plankEx = Exercise::create([
            'title' => 'Plank',
            'canonical_name' => 'plank',
            'log_type' => 'static-hold',
            'exercise_type' => 'static_hold',
        ]);

        $plankLog = LiftLog::create([
            'user_id' => $user->id,
            'exercise_id' => $plankEx->id,
            'log_type' => 'static-hold',
            'logged_at' => now(),
        ]);

        $plankSet = LiftSet::create([
            'lift_log_id' => $plankLog->id,
            'weight' => 0,
            'time' => 60,
        ]);

        // Run the migration
        $migration = include database_path('migrations/2026_09_04_113405_retype_carry_exercises_create_splits_and_purge_history.php');
        $migration->up();

        // (a) farmers_carry re-typed to load_output + weighted-carry-2-kb
        $farmersEx->refresh();
        $this->assertEquals('load_output', $farmersEx->exercise_type);
        $this->assertEquals('weighted-carry-2-kb', $farmersEx->log_type);

        // (b) 6 split defs created
        $splitDef = Exercise::where('canonical_name', 'mixed_rack_carry_kb')->first();
        $this->assertNotNull($splitDef);
        $this->assertEquals('load_output', $splitDef->exercise_type);
        $this->assertEquals('weighted-carry-2-kb', $splitDef->log_type);

        // (c) History for carry exercises PURGED (soft-deleted)
        $this->assertSoftDeleted('lift_logs', ['id' => $farmersLog->id]);
        $this->assertSoftDeleted('lift_sets', ['id' => $farmersSet->id]);
        $this->assertSoftDeleted('personal_records', ['id' => $farmersPr->id]);

        $this->assertSoftDeleted('lift_logs', ['id' => $retiredLog->id]);
        $this->assertSoftDeleted('lift_sets', ['id' => $retiredSet->id]);

        // (d) Genuine static hold UNTOUCHED
        $plankEx->refresh();
        $this->assertEquals('static_hold', $plankEx->exercise_type);
        $this->assertEquals('static-hold', $plankEx->log_type);
        $this->assertNotSoftDeleted('lift_logs', ['id' => $plankLog->id]);
        $this->assertNotSoftDeleted('lift_sets', ['id' => $plankSet->id]);
    }
}
