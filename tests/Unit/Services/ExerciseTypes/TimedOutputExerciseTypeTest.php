<?php

namespace Tests\Unit\Services\ExerciseTypes;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\LiftSet;
use App\Models\User;
use App\Services\ExerciseTypes\TimedOutputExerciseType;
use Tests\TestCase;

class TimedOutputExerciseTypeTest extends TestCase
{
    private TimedOutputExerciseType $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new TimedOutputExerciseType();
    }

    public function test_get_type_name(): void
    {
        $this->assertEquals('timed_output', $this->strategy->getTypeName());
    }

    public function test_can_calculate_1rm_returns_false(): void
    {
        $this->assertFalse($this->strategy->canCalculate1RM());
    }

    public function test_format_1rm_table_cell_display_returns_na(): void
    {
        $liftLog = new LiftLog();
        $this->assertEquals('N/A', $this->strategy->format1RMTableCellDisplay($liftLog));
    }

    public function test_process_lift_data_preserves_reps_and_time_and_nullifies_band_color(): void
    {
        $data = [
            'time' => 40,
            'reps' => 12,
            'band_color' => 'red',
        ];

        $processed = $this->strategy->processLiftData($data);

        $this->assertEquals(40, $processed['time']);
        $this->assertEquals(12, $processed['reps']);
        $this->assertNull($processed['band_color']);
    }

    public function test_format_mobile_summary_display_reps_only(): void
    {
        $liftLog = $this->createMockLiftLog(null, 12, 3);

        $summary = $this->strategy->formatMobileSummaryDisplay($liftLog);

        $this->assertEquals('', $summary['weight']);
        $this->assertFalse($summary['showWeight']);
        $this->assertEquals('3 x 12', $summary['repsSets']);
    }

    public function test_format_mobile_summary_display_duration_only(): void
    {
        $liftLog = $this->createMockLiftLog(40, null, 3);

        $summary = $this->strategy->formatMobileSummaryDisplay($liftLog);

        $this->assertEquals('40s', $summary['weight']);
        $this->assertTrue($summary['showWeight']);
        $this->assertEquals('3 x 0', $summary['repsSets']);
    }

    public function test_format_mobile_summary_display_both_duration_and_reps(): void
    {
        $liftLog = $this->createMockLiftLog(40, 12, 3);

        $summary = $this->strategy->formatMobileSummaryDisplay($liftLog);

        $this->assertEquals('40s', $summary['weight']);
        $this->assertTrue($summary['showWeight']);
        $this->assertEquals('3 x 12', $summary['repsSets']);
    }

    public function test_format_mobile_summary_display_minute_duration(): void
    {
        $liftLog = $this->createMockLiftLog(90, 10, 2);

        $summary = $this->strategy->formatMobileSummaryDisplay($liftLog);

        $this->assertEquals('1m 30s', $summary['weight']);
        $this->assertTrue($summary['showWeight']);
        $this->assertEquals('2 x 10', $summary['repsSets']);
    }

    private function createMockLiftLog(?int $duration, ?int $reps, int $sets = 3): LiftLog
    {
        $liftLog = new LiftLog();

        $mockExercise = new Exercise();
        $mockExercise->exercise_type = 'timed_output';
        $liftLog->setRelation('exercise', $mockExercise);

        $mockUser = new User();
        $liftLog->setRelation('user', $mockUser);

        $liftSets = collect();
        for ($i = 0; $i < $sets; $i++) {
            $set = new LiftSet();
            $set->reps = $reps;
            $set->time = $duration;
            $set->weight = 0;
            $set->unit = 'lbs';
            $liftSets->push($set);
        }
        $liftLog->setRelation('liftSets', $liftSets);

        return $liftLog;
    }
}
