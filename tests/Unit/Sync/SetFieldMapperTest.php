<?php

namespace Tests\Unit\Sync;

use App\Models\LiftSet;
use App\Sync\Services\SetFieldMapper;
use Tests\TestCase;

class SetFieldMapperTest extends TestCase
{
    private SetFieldMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new SetFieldMapper();
    }

    public function test_map_to_columns_all_types(): void
    {
        // 1. barbell, single-dumbbell, dual-dumbbell
        foreach (['barbell', 'single-dumbbell', 'dual-dumbbell'] as $type) {
            $mapped = $this->mapper->mapToColumns($type, ['weight' => 100, 'reps' => 5], 'lbs');
            $this->assertEquals(100, $mapped['weight']);
            $this->assertEquals(5, $mapped['reps']);
            $this->assertEquals('lbs', $mapped['unit']);
        }

        // 2. bodyweight, added-weight
        foreach (['bodyweight', 'added-weight'] as $type) {
            $mapped = $this->mapper->mapToColumns($type, ['addedWeight' => 15, 'reps' => 10], 'kg');
            $this->assertEquals(15, $mapped['weight']);
            $this->assertEquals(10, $mapped['reps']);
            $this->assertEquals('kg', $mapped['unit']);
        }

        // 3. kettlebell
        $mapped = $this->mapper->mapToColumns('kettlebell', ['kbWeight' => 24, 'reps' => 8], 'lbs');
        $this->assertEquals(24, $mapped['weight']);
        $this->assertEquals(8, $mapped['reps']);
        $this->assertEquals('lbs', $mapped['unit']);

        // 4. ball
        $mapped = $this->mapper->mapToColumns('ball', ['ballWeight' => 20, 'reps' => 15], 'lbs');
        $this->assertEquals(20, $mapped['weight']);
        $this->assertEquals(15, $mapped['reps']);

        // 5. bodyweight-reps
        $mapped = $this->mapper->mapToColumns('bodyweight-reps', ['reps' => 12], 'lbs');
        $this->assertEquals(12, $mapped['reps']);

        // 6. static-hold
        $mapped = $this->mapper->mapToColumns('static-hold', ['duration' => 60], 'lbs');
        $this->assertEquals(60, $mapped['time']);

        // 7. weighted-carry logTypes
        $mapped = $this->mapper->mapToColumns('weighted-carry-1-kb', ['kbWeight' => 32, 'distance' => 50, 'distanceUnit' => 'm', 'duration' => 30], 'lbs');
        $this->assertEquals(32, $mapped['weight']);
        $this->assertEquals(50, $mapped['distance']);
        $this->assertEquals('m', $mapped['distance_unit']);
        $this->assertEquals(30, $mapped['time']);

        $mappedDb = $this->mapper->mapToColumns('weighted-carry-1-db', ['weight' => 40, 'distance' => 60, 'distance_unit' => 'ft', 'duration' => 25], 'lbs');
        $this->assertEquals(40, $mappedDb['weight']);
        $this->assertEquals(60, $mappedDb['distance']);
        $this->assertEquals('ft', $mappedDb['distance_unit']);
        $this->assertEquals(25, $mappedDb['time']);

        $mappedBall = $this->mapper->mapToColumns('weighted-carry-ball', ['ballWeight' => 50, 'distance' => 30, 'duration' => 20], 'lbs');
        $this->assertEquals(50, $mappedBall['weight']);
        $this->assertEquals(30, $mappedBall['distance']);
        $this->assertEquals(20, $mappedBall['time']);

        // 9. cardio
        $mapped = $this->mapper->mapToColumns('cardio', ['distance' => 5000, 'distanceUnit' => 'm', 'time' => 1200, 'calories' => 400], 'lbs');
        $this->assertEquals(5000, $mapped['distance']);
        $this->assertEquals('m', $mapped['distance_unit']);
        $this->assertEquals(1200, $mapped['time']);
        $this->assertEquals(400, $mapped['calories']);

        // 10. cardio-calories
        $mapped = $this->mapper->mapToColumns('cardio-calories', ['calories' => 250], 'lbs');
        $this->assertEquals(250, $mapped['calories']);

        // 11. cardio-distance
        $mapped = $this->mapper->mapToColumns('cardio-distance', ['distance' => 10, 'distanceUnit' => 'km', 'time' => 3600], 'lbs');
        $this->assertEquals(10, $mapped['distance']);
        $this->assertEquals('km', $mapped['distance_unit']);
        $this->assertEquals(3600, $mapped['time']);

        // 12. banded
        $mapped = $this->mapper->mapToColumns('banded', ['bandColor' => 'Red', 'reps' => 15], 'lbs');
        $this->assertEquals('Red', $mapped['band_color']);
        $this->assertEquals(15, $mapped['reps']);
    }

    public function test_map_to_columns_missing_optional_fields(): void
    {
        // bodyweight/added-weight without addedWeight should default to 0
        foreach (['bodyweight', 'added-weight'] as $type) {
            $mapped = $this->mapper->mapToColumns($type, ['reps' => 10], 'lbs');
            $this->assertEquals(0, $mapped['weight'], "$type: missing addedWeight should default to 0");
            $this->assertEquals(10, $mapped['reps']);
        }

        // barbell without weight should be null (caller must validate)
        $mapped = $this->mapper->mapToColumns('barbell', ['reps' => 5], 'lbs');
        $this->assertNull($mapped['weight']);
        $this->assertEquals(5, $mapped['reps']);

        // kettlebell without kbWeight
        $mapped = $this->mapper->mapToColumns('kettlebell', ['reps' => 8], 'lbs');
        $this->assertNull($mapped['weight']);
        $this->assertEquals(8, $mapped['reps']);

        // ball without ballWeight
        $mapped = $this->mapper->mapToColumns('ball', ['reps' => 12], 'lbs');
        $this->assertNull($mapped['weight']);
        $this->assertEquals(12, $mapped['reps']);

        // bodyweight-reps without reps
        $mapped = $this->mapper->mapToColumns('bodyweight-reps', [], 'lbs');
        $this->assertNull($mapped['reps']);

        // static-hold without duration
        $mapped = $this->mapper->mapToColumns('static-hold', [], 'lbs');
        $this->assertNull($mapped['time']);

        // weighted-carry without weight or duration
        $mapped = $this->mapper->mapToColumns('weighted-carry', [], 'lbs');
        $this->assertNull($mapped['weight']);
        $this->assertNull($mapped['time']);

        // cardio with partial data
        $mapped = $this->mapper->mapToColumns('cardio', ['distance' => 5000], 'lbs');
        $this->assertEquals(5000, $mapped['distance']);
        $this->assertNull($mapped['distance_unit']);
        $this->assertNull($mapped['time']);
        $this->assertNull($mapped['calories']);

        // banded without bandColor
        $mapped = $this->mapper->mapToColumns('banded', ['reps' => 15], 'lbs');
        $this->assertNull($mapped['band_color']);
        $this->assertEquals(15, $mapped['reps']);
    }

    public function test_map_from_columns_all_types(): void
    {
        // 1. barbell
        $set = new LiftSet(['weight' => 100, 'reps' => 5]);
        $mapped = $this->mapper->mapFromColumns('barbell', $set);
        $this->assertEquals(100, $mapped['weight']);
        $this->assertEquals(5, $mapped['reps']);

        // 2. bodyweight — returns 'weight' (Athlete handles rename to addedWeight)
        $set = new LiftSet(['weight' => 20, 'reps' => 8]);
        $mapped = $this->mapper->mapFromColumns('bodyweight', $set);
        $this->assertEquals(20, $mapped['weight']);
        $this->assertEquals(8, $mapped['reps']);

        // 3. kettlebell — returns 'weight' (Athlete handles rename to kbWeight + unit conversion)
        $set = new LiftSet(['weight' => 16, 'reps' => 12]);
        $mapped = $this->mapper->mapFromColumns('kettlebell', $set);
        $this->assertEquals(16, $mapped['weight']);
        $this->assertEquals(12, $mapped['reps']);

        // 4. ball — returns 'weight' (Athlete handles rename to ballWeight)
        $set = new LiftSet(['weight' => 15, 'reps' => 20]);
        $mapped = $this->mapper->mapFromColumns('ball', $set);
        $this->assertEquals(15, $mapped['weight']);
        $this->assertEquals(20, $mapped['reps']);

        // 5. bodyweight-reps
        $set = new LiftSet(['reps' => 15]);
        $mapped = $this->mapper->mapFromColumns('bodyweight-reps', $set);
        $this->assertEquals(15, $mapped['reps']);

        // 6. static-hold
        $set = new LiftSet(['time' => 90]);
        $mapped = $this->mapper->mapFromColumns('static-hold', $set);
        $this->assertEquals(90, $mapped['duration']);

        // 7. weighted-carry logTypes
        $set = new LiftSet(['weight' => 60, 'distance' => 100, 'distance_unit' => 'm', 'time' => 30]);
        $mapped = $this->mapper->mapFromColumns('weighted-carry-2-kb', $set);
        $this->assertEquals(60, $mapped['weight']);
        $this->assertEquals(100, $mapped['distance']);
        $this->assertEquals('m', $mapped['distance_unit']);
        $this->assertEquals(30, $mapped['duration']);

        // 9. cardio — returns snake_case (Athlete handles rename)
        $set = new LiftSet(['distance' => 1609.34, 'distance_unit' => 'm', 'time' => 480, 'calories' => 150]);
        $mapped = $this->mapper->mapFromColumns('cardio', $set);
        $this->assertEquals(1609.34, $mapped['distance']);
        $this->assertEquals('m', $mapped['distance_unit']);
        $this->assertEquals(480, $mapped['time']);
        $this->assertEquals(150, $mapped['calories']);

        // 10. cardio-calories
        $set = new LiftSet(['calories' => 300]);
        $mapped = $this->mapper->mapFromColumns('cardio-calories', $set);
        $this->assertEquals(300, $mapped['calories']);

        // 11. cardio-distance — returns snake_case
        $set = new LiftSet(['distance' => 5, 'distance_unit' => 'km', 'time' => 1500]);
        $mapped = $this->mapper->mapFromColumns('cardio-distance', $set);
        $this->assertEquals(5, $mapped['distance']);
        $this->assertEquals('km', $mapped['distance_unit']);
        $this->assertEquals(1500, $mapped['time']);

        // 12. banded — returns snake_case
        $set = new LiftSet(['band_color' => 'Blue', 'reps' => 10]);
        $mapped = $this->mapper->mapFromColumns('banded', $set);
        $this->assertEquals('Blue', $mapped['band_color']);
        $this->assertEquals(10, $mapped['reps']);
    }

    public function test_timed_reps_mapping_round_trip(): void
    {
        // (a) both present
        $toColsBoth = $this->mapper->mapToColumns('timed-reps', ['duration' => 40, 'reps' => 12], 'lbs');
        $this->assertEquals(40, $toColsBoth['time']);
        $this->assertEquals(12, $toColsBoth['reps']);

        $setBoth = new LiftSet(['time' => 40, 'reps' => 12]);
        $fromColsBoth = $this->mapper->mapFromColumns('timed-reps', $setBoth);
        $this->assertEquals(40, $fromColsBoth['duration']);
        $this->assertEquals(12, $fromColsBoth['reps']);

        // (b) reps-only (duration null)
        $toColsReps = $this->mapper->mapToColumns('timed-reps', ['reps' => 12], 'lbs');
        $this->assertNull($toColsReps['time']);
        $this->assertEquals(12, $toColsReps['reps']);

        $setReps = new LiftSet(['time' => null, 'reps' => 12]);
        $fromColsReps = $this->mapper->mapFromColumns('timed-reps', $setReps);
        $this->assertNull($fromColsReps['duration']);
        $this->assertEquals(12, $fromColsReps['reps']);

        // (c) duration-only (reps null)
        $toColsDur = $this->mapper->mapToColumns('timed-reps', ['duration' => 40], 'lbs');
        $this->assertEquals(40, $toColsDur['time']);
        $this->assertNull($toColsDur['reps']);

        $setDur = new LiftSet(['time' => 40, 'reps' => null]);
        $fromColsDur = $this->mapper->mapFromColumns('timed-reps', $setDur);
        $this->assertEquals(40, $fromColsDur['duration']);
        $this->assertNull($fromColsDur['reps']);
    }
}
