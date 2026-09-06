<?php

namespace Tests\Unit\Services\ExerciseTypes;

use App\Services\ExerciseTypes\LoadOutputExerciseType;
use Tests\TestCase;

class LoadOutputExerciseTypeTest extends TestCase
{
    private LoadOutputExerciseType $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new LoadOutputExerciseType();
    }

    public function test_get_type_name(): void
    {
        $this->assertEquals('load_output', $this->strategy->getTypeName());
    }

    public function test_distance_unit_select_options_use_value_label_shape(): void
    {
        $definitions = $this->strategy->getFormFieldDefinitions();
        $byName = collect($definitions)->keyBy('name');

        $this->assertTrue($byName->has('distance_unit'));
        $distanceUnit = $byName['distance_unit'];
        $this->assertEquals('select', $distanceUnit['type']);

        // The form-field blade iterates options and reads $option['value'] / $option['label'],
        // so each option MUST be an associative array with those keys (not a bare string).
        foreach ($distanceUnit['options'] as $option) {
            $this->assertIsArray($option);
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
        }

        $values = collect($distanceUnit['options'])->pluck('value')->all();
        $this->assertEqualsCanonicalizing(['m', 'ft'], $values);
    }
}
