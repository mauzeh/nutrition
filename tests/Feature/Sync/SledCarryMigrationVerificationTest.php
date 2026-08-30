<?php

namespace Tests\Feature\Sync;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\LiftSet;
use App\Models\User;
use App\Sync\Services\SetFieldMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SledCarryMigrationVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_b_retypes_exercise_preserves_log_type_and_cleans_sled_prs(): void
    {
        $user = User::factory()->create();

        $sledEx = Exercise::create([
            'title' => 'Sled Push',
            'canonical_name' => 'sled_push',
            'log_type' => 'sled',
            'exercise_type' => 'sled',
        ]);

        $carryEx = Exercise::create([
            'title' => 'Farmers Walk',
            'canonical_name' => 'farmers_walk',
            'log_type' => 'weighted-carry',
            'exercise_type' => 'static_hold',
        ]);

        $holdEx = Exercise::create([
            'title' => 'Plank',
            'canonical_name' => 'plank',
            'log_type' => 'static-hold',
            'exercise_type' => 'static_hold',
        ]);

        $sledLog = LiftLog::create([
            'user_id' => $user->id,
            'exercise_id' => $sledEx->id,
            'log_type' => 'sled',
            'logged_at' => now(),
        ]);

        $sledSet = LiftSet::create([
            'lift_log_id' => $sledLog->id,
            'weight' => 100,
            'unit' => 'lbs',
            'distance' => 50,
            'distance_unit' => 'm',
            'time' => 30,
        ]);

        $carryLog = LiftLog::create([
            'user_id' => $user->id,
            'exercise_id' => $carryEx->id,
            'log_type' => 'weighted-carry',
            'logged_at' => now(),
        ]);

        $carrySet = LiftSet::create([
            'lift_log_id' => $carryLog->id,
            'weight' => 50,
            'unit' => 'lbs',
            'time' => 45,
        ]);

        // Run Migration A to allow sled_* pr_type values during transitional window
        $migrationA = include database_path('migrations/2026_08_30_142425_add_load_output_pr_types_to_personal_records.php');
        $migrationA->up();

        // Insert legacy sled_* personal records directly on sledEx
        DB::table('personal_records')->insert([
            'user_id' => $user->id,
            'exercise_id' => $sledEx->id,
            'lift_log_id' => $sledLog->id,
            'pr_type' => 'sled_weight',
            'value' => 100,
            'achieved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run Migration B
        $migration = include database_path('migrations/2026_08_30_142524_retype_sled_and_carry_exercises_to_load_output.php');
        $migration->up();

        // (a) exercises.exercise_type became load_output
        $this->assertEquals('load_output', $sledEx->fresh()->exercise_type);
        $this->assertEquals('load_output', $carryEx->fresh()->exercise_type);

        // (b) lift_logs.log_type is STILL sled/weighted-carry
        $this->assertEquals('sled', $sledLog->fresh()->log_type);
        $this->assertEquals('weighted-carry', $carryLog->fresh()->log_type);

        // (c) no personal_records.pr_type references sled_*
        $sledPrCount = DB::table('personal_records')
            ->whereIn('pr_type', ['sled_weight', 'sled_distance', 'sled_volume'])
            ->count();
        $this->assertEquals(0, $sledPrCount);

        // (d) genuine static-hold / dual-kettlebell rows are untouched
        $this->assertEquals('static_hold', $holdEx->fresh()->exercise_type);

        // Concrete regression check: mapFromColumns using log_type produces non-empty body
        $mapper = new SetFieldMapper();
        $sledMapped = $mapper->mapFromColumns($sledLog->fresh()->log_type, $sledSet->fresh());
        $this->assertNotEmpty($sledMapped);
        $this->assertEquals(100, $sledMapped['weight']);
        $this->assertEquals(50, $sledMapped['distance']);

        $carryMapped = $mapper->mapFromColumns($carryLog->fresh()->log_type, $carrySet->fresh());
        $this->assertNotEmpty($carryMapped);
        $this->assertEquals(50, $carryMapped['weight']);
        $this->assertEquals(45, $carryMapped['duration']);
    }
}
