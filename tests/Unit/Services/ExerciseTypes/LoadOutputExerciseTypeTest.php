<?php

namespace Tests\Unit\Services\ExerciseTypes;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\LiftSet;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\ExerciseTypes\LoadOutputExerciseType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoadOutputExerciseTypeTest extends TestCase
{
    use RefreshDatabase;

    private LoadOutputExerciseType $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new LoadOutputExerciseType();
    }

    /** @test */
    public function it_normalizes_ft_to_meters_correctly()
    {
        $this->assertEquals(0, LoadOutputExerciseType::normalizeDistanceToMeters(0, 'ft'));
        $this->assertEquals(15, LoadOutputExerciseType::normalizeDistanceToMeters(50, 'ft')); // 50 * 0.3048 = 15.24 -> 15
        $this->assertEquals(30, LoadOutputExerciseType::normalizeDistanceToMeters(100, 'ft')); // 100 * 0.3048 = 30.48 -> 30
        $this->assertEquals(50, LoadOutputExerciseType::normalizeDistanceToMeters(50, 'm'));
    }

    /** @test */
    public function it_calculates_metrics_with_load_normalization_and_distance_meters()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['exercise_type' => 'load_output']);
        $log = LiftLog::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercise->id]);

        LiftSet::factory()->create([
            'lift_log_id' => $log->id,
            'weight' => 100,
            'unit' => 'kg', // 100 kg = 220.462 lbs -> rounded 220.462
            'distance' => 50,
            'distance_unit' => 'ft', // 50 ft = 15 m
            'time' => 45,
        ]);

        $metrics = $this->strategy->calculateCurrentMetrics($log);

        $this->assertEquals(220.0, $metrics['load']);
        $this->assertEquals(15, $metrics['distance']);
        $this->assertEquals(45, $metrics['duration']);
        $this->assertArrayHasKey('220|15', $metrics['speedBuckets']);
        $this->assertEquals(45, $metrics['speedBuckets']['220|15']);
    }

    /** @test */
    public function it_detects_load_distance_duration_and_speed_prs()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['exercise_type' => 'load_output']);

        // First log - sets baseline
        $log1 = LiftLog::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercise->id, 'created_at' => now()->subDays(2)]);
        LiftSet::factory()->create([
            'lift_log_id' => $log1->id,
            'weight' => 100,
            'unit' => 'lbs',
            'distance' => 50,
            'distance_unit' => 'm',
            'time' => 60,
        ]);
        $metrics1 = $this->strategy->calculateCurrentMetrics($log1);
        $prs1 = $this->strategy->compareToPrevious($metrics1, new \Illuminate\Database\Eloquent\Collection([]), $log1);

        $this->assertCount(3, $prs1); // load, distance, duration (speed does not fire on first log)
        $this->assertEquals('load', $prs1[0]['type']);
        $this->assertEquals('distance', $prs1[1]['type']);
        $this->assertEquals('duration', $prs1[2]['type']);

        // Second log - beats speed (same load & distance, strictly shorter duration)
        $log2 = LiftLog::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercise->id, 'created_at' => now()->subDay()]);
        LiftSet::factory()->create([
            'lift_log_id' => $log2->id,
            'weight' => 100,
            'unit' => 'lbs',
            'distance' => 50,
            'distance_unit' => 'm',
            'time' => 45, // faster!
        ]);
        $metrics2 = $this->strategy->calculateCurrentMetrics($log2);
        $prs2 = $this->strategy->compareToPrevious($metrics2, new \Illuminate\Database\Eloquent\Collection([$log1]), $log2);

        $this->assertCount(1, $prs2);
        $this->assertEquals('speed', $prs2[0]['type']);
        $this->assertEquals(45, $prs2[0]['value']);
        $this->assertEquals(60, $prs2[0]['previous_value']);
        $this->assertEquals(100.0, $prs2[0]['weight']);
        $this->assertEquals(50, $prs2[0]['rep_count']);

        // Third log - equal or longer duration on same bucket does NOT fire speed PR
        $log3 = LiftLog::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercise->id, 'created_at' => now()]);
        LiftSet::factory()->create([
            'lift_log_id' => $log3->id,
            'weight' => 100,
            'unit' => 'lbs',
            'distance' => 50,
            'distance_unit' => 'm',
            'time' => 50, // slower than 45
        ]);
        $metrics3 = $this->strategy->calculateCurrentMetrics($log3);
        $prs3 = $this->strategy->compareToPrevious($metrics3, new \Illuminate\Database\Eloquent\Collection([$log1, $log2]), $log3);

        $this->assertEmpty($prs3);
    }

    /** @test */
    public function it_proves_cross_unit_load_d1_comparisons()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['exercise_type' => 'load_output']);

        // Log 1: 100 kg = 220.462 lbs -> normalized ~220.462
        $log1 = LiftLog::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercise->id, 'created_at' => now()->subDays(2)]);
        LiftSet::factory()->create([
            'lift_log_id' => $log1->id,
            'weight' => 100,
            'unit' => 'kg',
            'distance' => 50,
            'distance_unit' => 'm',
            'time' => 60,
        ]);

        // Log 2: 220 lbs (less than 220.462 lbs) -> NOT a load PR
        $log2 = LiftLog::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercise->id, 'created_at' => now()->subDay()]);
        LiftSet::factory()->create([
            'lift_log_id' => $log2->id,
            'weight' => 220,
            'unit' => 'lbs',
            'distance' => 50,
            'distance_unit' => 'm',
            'time' => 60,
        ]);

        $metrics2 = $this->strategy->calculateCurrentMetrics($log2);
        $prs2 = $this->strategy->compareToPrevious($metrics2, new \Illuminate\Database\Eloquent\Collection([$log1]), $log2);
        $loadPRs2 = array_filter($prs2, fn ($p) => $p['type'] === 'load');
        $this->assertEmpty($loadPRs2);

        // Log 3: 225 lbs (greater than 220.462 lbs) -> IS a load PR
        $log3 = LiftLog::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercise->id, 'created_at' => now()]);
        LiftSet::factory()->create([
            'lift_log_id' => $log3->id,
            'weight' => 225,
            'unit' => 'lbs',
            'distance' => 50,
            'distance_unit' => 'm',
            'time' => 60,
        ]);

        $metrics3 = $this->strategy->calculateCurrentMetrics($log3);
        $prs3 = $this->strategy->compareToPrevious($metrics3, new \Illuminate\Database\Eloquent\Collection([$log1, $log2]), $log3);
        $loadPRs3 = array_values(array_filter($prs3, fn ($p) => $p['type'] === 'load'));
        $this->assertCount(1, $loadPRs3);
        $this->assertEquals(225.0, $loadPRs3[0]['value']);
    }

    /** @test */
    public function it_does_not_trigger_speed_pr_for_different_bucket()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['exercise_type' => 'load_output']);

        // Log 1: 100 lbs x 50m in 60s
        $log1 = LiftLog::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercise->id, 'created_at' => now()->subDay()]);
        LiftSet::factory()->create([
            'lift_log_id' => $log1->id,
            'weight' => 100,
            'unit' => 'lbs',
            'distance' => 50,
            'distance_unit' => 'm',
            'time' => 60,
        ]);

        // Log 2: 120 lbs x 50m in 40s (different load bucket!) -> Faster time, but no previous baseline for 120lbs x 50m bucket
        $log2 = LiftLog::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercise->id, 'created_at' => now()]);
        LiftSet::factory()->create([
            'lift_log_id' => $log2->id,
            'weight' => 120,
            'unit' => 'lbs',
            'distance' => 50,
            'distance_unit' => 'm',
            'time' => 40,
        ]);

        $metrics2 = $this->strategy->calculateCurrentMetrics($log2);
        $prs2 = $this->strategy->compareToPrevious($metrics2, new \Illuminate\Database\Eloquent\Collection([$log1]), $log2);
        $speedPRs = array_filter($prs2, fn ($p) => $p['type'] === 'speed');
        $this->assertEmpty($speedPRs);
    }

    /** @test */
    public function it_renders_comparison_value_and_pr_table_components_correctly()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['exercise_type' => 'load_output']);
        $log = LiftLog::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercise->id]);

        LiftSet::factory()->create([
            'lift_log_id' => $log->id,
            'weight' => 100,
            'unit' => 'lbs',
            'distance' => 50,
            'distance_unit' => 'm',
            'time' => 30,
        ]);

        $metrics = $this->strategy->calculateCurrentMetrics($log);

        $loadPR = new PersonalRecord(['pr_type' => 'load', 'value' => 100, 'unit' => 'lbs']);
        $distPR = new PersonalRecord(['pr_type' => 'distance', 'value' => 50, 'unit' => 'm']);
        $durPR = new PersonalRecord(['pr_type' => 'duration', 'value' => 30, 'unit' => 's']);
        $speedPR = new PersonalRecord(['pr_type' => 'speed', 'value' => 30, 'weight' => 100, 'rep_count' => 50, 'unit' => 'lbs']);

        $this->assertEquals('100 lbs', $this->strategy->comparisonValue($loadPR, $metrics, $log));
        $this->assertEquals('50m', $this->strategy->comparisonValue($distPR, $metrics, $log));
        $this->assertEquals('30s', $this->strategy->comparisonValue($durPR, $metrics, $log));
        $this->assertEquals('30s', $this->strategy->comparisonValue($speedPR, $metrics, $log));

        // Test PRRecordsComponentAssembler integration
        $rowConfig = new \App\Services\LiftLogTableRowBuilder\RowConfig();
        $components = \App\Services\LiftLogTableRowBuilder\PRRecordsComponentAssembler::assemble($log, $rowConfig);
        $this->assertNotEmpty($components);
    }
}
