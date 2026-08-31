<?php

namespace Tests\Unit\PR;

use App\Services\PR\Comparators;
use App\Services\PR\PrEngine;
use App\Services\PR\Reductions;
use Tests\TestCase;

class PrEngineTest extends TestCase
{
    public function test_reductions_max_of()
    {
        $sets = [
            ['weight' => 100, 'reps' => 5],
            ['weight' => 120, 'reps' => 3],
            ['weight' => 110, 'reps' => 4],
        ];

        $this->assertEquals(120, Reductions::maxOf($sets, 'weight'));
    }

    public function test_reductions_min_of()
    {
        $sets = [
            ['time' => 60],
            ['time' => 45],
            ['time' => 50],
        ];

        $this->assertEquals(45, Reductions::minOf($sets, 'time'));
    }

    public function test_reductions_sum_of()
    {
        $sets = [
            ['distance' => 500],
            ['distance' => 700],
        ];

        $this->assertEquals(1200, Reductions::sumOf($sets, 'distance'));
    }

    public function test_reductions_sum_product()
    {
        $sets = [
            ['weight' => 100, 'reps' => 10], // 1000
            ['weight' => 200, 'reps' => 5],  // 1000
        ];

        $this->assertEquals(2000, Reductions::sumProduct($sets, ['weight', 'reps']));
    }

    public function test_reductions_estimated_1rm()
    {
        $sets = [
            ['weight' => 100, 'reps' => 1], // 100
            ['weight' => 100, 'reps' => 10], // 100 * (1 + 10/30) = 133.333
        ];

        $this->assertEqualsWithDelta(133.333, Reductions::estimated1RM($sets, 'weight', 'reps'), 0.001);
    }

    public function test_reductions_per_key_count_and_sum()
    {
        $sets = [
            ['weight' => 100, 'reps' => 5],
            ['weight' => 100, 'reps' => 5],
            ['weight' => 120, 'reps' => 3],
        ];

        $descriptorCount = [
            'keyFields' => ['weight'],
            'aggregate' => 'count',
        ];
        $this->assertEquals(['100' => 2, '120' => 1], Reductions::perKey($sets, $descriptorCount));

        $descriptorSum = [
            'keyFields' => ['weight'],
            'aggregate' => 'sumReps',
        ];
        $this->assertEquals(['100' => 10, '120' => 3], Reductions::perKey($sets, $descriptorSum));
    }

    public function test_comparators_scalar_best()
    {
        $descriptor = ['direction' => 'max', 'tolerance' => 'unit'];

        // Initial PR
        $res1 = Comparators::scalarBest(100, null, $descriptor);
        $this->assertTrue($res1['isPR']);

        // Beat with unit tolerance (+0.1)
        $res2 = Comparators::scalarBest(100.05, 100.0, $descriptor);
        $this->assertFalse($res2['isPR']); // 100.05 is not > 100.1

        $res3 = Comparators::scalarBest(100.2, 100.0, $descriptor);
        $this->assertTrue($res3['isPR']); // 100.2 > 100.1
    }

    public function test_engine_weightlifting_pr_detection()
    {
        $engine = new PrEngine();

        $log = [
            'liftSets' => [
                ['weight' => 200, 'reps' => 5],
                ['weight' => 220, 'reps' => 3],
            ],
        ];

        $metrics = $engine->computeMetrics($log, 'weightlifting');
        $this->assertArrayHasKey('one_rm', $metrics);
        $this->assertArrayHasKey('rep_specific', $metrics);
        $this->assertArrayHasKey('volume', $metrics);

        $detected = $engine->detectPRs($metrics, [], 'weightlifting');
        $this->assertNotEmpty($detected['prs']);
    }
}

