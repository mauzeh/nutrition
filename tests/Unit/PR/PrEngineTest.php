<?php

namespace Tests\Unit\PR;

use App\Services\PR\Comparators;
use App\Services\PR\PrEngine;
use App\Services\PR\Reductions;
use Tests\TestCase;

/**
 * Pure, DB-free spec for the generic PR engine.
 *
 * This file is the single authoritative test of PR CALCULATION LOGIC. It owns every
 * reduction primitive, every comparison primitive, the modifiers (suppressDominated,
 * requirePrevious, minGroupSize), unit normalization (kg->lbs, ft->m), min-direction
 * (speed), the "why not" reason output, and per-family descriptor behavior.
 *
 * It intentionally does NOT touch the database, factories, HTTP, or the assembler —
 * those layers are covered by a thin set of feature tests. Calculation is verified
 * here, in isolation, with plain arrays.
 *
 * Absorbs the coverage of the deleted UnitConversionInPRDetectionTest and
 * LoadOutputExerciseTypeTest.
 */
class PrEngineTest extends TestCase
{
    // ─── Reductions ────────────────────────────────────────────────────────────

    public function test_max_of_returns_largest_field_value(): void
    {
        $sets = [['weight' => 100], ['weight' => 120], ['weight' => 110]];
        $this->assertEquals(120, Reductions::maxOf($sets, 'weight'));
    }

    public function test_min_of_returns_smallest_field_value(): void
    {
        $sets = [['time' => 60], ['time' => 45], ['time' => 50]];
        $this->assertEquals(45, Reductions::minOf($sets, 'time'));
    }

    public function test_sum_of_totals_a_field(): void
    {
        $sets = [['distance' => 500], ['distance' => 700]];
        $this->assertEquals(1200, Reductions::sumOf($sets, 'distance'));
    }

    public function test_sum_product_multiplies_factors_per_set_and_totals(): void
    {
        $sets = [['weight' => 100, 'reps' => 10], ['weight' => 200, 'reps' => 5]];
        $this->assertEquals(2000, Reductions::sumProduct($sets, ['weight', 'reps']));
    }

    public function test_sum_product_treats_pure_bodyweight_zero_weight_as_reps_only(): void
    {
        // All-zero-weight log => weight factor becomes 1, so volume == total reps.
        $sets = [['weight' => 0, 'reps' => 12], ['weight' => 0, 'reps' => 8]];
        $this->assertEquals(20, Reductions::sumProduct($sets, ['weight', 'reps']));
    }

    public function test_estimated_1rm_uses_epley_and_treats_single_rep_as_raw(): void
    {
        $sets = [['weight' => 100, 'reps' => 1], ['weight' => 100, 'reps' => 10]];
        // Epley variant matching the frozen cross-app contract (coefficient 0.0333, reps
        // capped at 10): 100 * (1 + 0.0333 * 10) = 133.3, which beats the raw 100.
        $this->assertEqualsWithDelta(133.3, Reductions::estimated1RM($sets, 'weight', 'reps'), 0.001);
    }

    public function test_per_key_count_and_sum_reps(): void
    {
        $sets = [
            ['weight' => 100, 'reps' => 5],
            ['weight' => 100, 'reps' => 5],
            ['weight' => 120, 'reps' => 3],
        ];
        $this->assertEquals(['100' => 2, '120' => 1], Reductions::perKey($sets, ['keyFields' => ['weight'], 'aggregate' => 'count']));
        $this->assertEquals(['100' => 10, '120' => 3], Reductions::perKey($sets, ['keyFields' => ['weight'], 'aggregate' => 'sumReps']));
    }

    public function test_per_key_max_value_keeps_best_per_key(): void
    {
        // rep_specific: best weight at each rep count.
        $sets = [
            ['reps' => 5, 'weight' => 200],
            ['reps' => 5, 'weight' => 210],
            ['reps' => 3, 'weight' => 250],
        ];
        $desc = ['keyFields' => ['reps'], 'aggregate' => 'maxValue', 'valueField' => 'weight'];
        $this->assertEquals(['5' => 210, '3' => 250], Reductions::perKey($sets, $desc));
    }

    public function test_per_key_min_value_composite_key_for_speed(): void
    {
        // speed: min duration at a (load|distance) bucket.
        $sets = [
            ['weight' => 100, 'distance' => 50, 'duration' => 60],
            ['weight' => 100, 'distance' => 50, 'duration' => 45],
        ];
        $desc = ['keyFields' => ['load', 'distance'], 'aggregate' => 'minValue', 'valueField' => 'duration'];
        $this->assertEquals(['100|50' => 45], Reductions::perKey($sets, $desc));
    }

    public function test_load_role_token_resolves_to_weight_column_for_kg_conversion(): void
    {
        // 100 kg -> 220.46 lbs when descriptor field is 'load'
        $sets = [['weight' => 100, 'unit' => 'kg', 'reps' => 1]];
        $this->assertEqualsWithDelta(220.46, Reductions::maxOf($sets, 'load'), 0.01);
    }

    public function test_load_role_token_speed_bucket_rounds_mass_to_whole_pound(): void
    {
        // speed descriptor with keyFields ['load', 'distance'] and 100 kg input (220.46 lbs) => bucket '220|50'
        $sets = [
            ['weight' => 100, 'unit' => 'kg', 'distance' => 50, 'duration' => 60],
            ['weight' => 100, 'unit' => 'kg', 'distance' => 50, 'duration' => 45],
        ];
        $desc = ['keyFields' => ['load', 'distance'], 'aggregate' => 'minValue', 'valueField' => 'duration'];
        $this->assertEquals(['220|50' => 45], Reductions::perKey($sets, $desc));
    }

    // ─── Unit normalization (absorbs UnitConversionInPRDetectionTest) ────────────

    public function test_weight_in_kg_is_normalized_to_lbs_for_comparison(): void
    {
        // 100 kg -> 220.46 lbs. maxOf reads the normalized value.
        $sets = [['weight' => 100, 'unit' => 'kg', 'reps' => 1]];
        $this->assertEqualsWithDelta(220.46, Reductions::maxOf($sets, 'weight'), 0.01);
    }

    public function test_mixed_unit_weight_comparison_is_correct(): void
    {
        // 100 kg (220.46 lbs) must beat a stored 200 lbs; 80 kg (176 lbs) must not.
        $engine = new PrEngine();
        $lbsHistory = ['one_rm' => 200.0, 'rep_specific' => [], 'volume' => 0, 'density' => [], 'hypertrophy' => []];

        $metricsWin = $engine->computeMetrics(['liftSets' => [['weight' => 100, 'unit' => 'kg', 'reps' => 1]]], 'weightlifting');
        $win = $engine->detectPRs($metricsWin, ['one_rm' => 200.0], 'weightlifting');
        $this->assertContains('one_rm', array_column($win['prs'], 'type'));

        $metricsLose = $engine->computeMetrics(['liftSets' => [['weight' => 80, 'unit' => 'kg', 'reps' => 1]]], 'weightlifting');
        $lose = $engine->detectPRs($metricsLose, ['one_rm' => 200.0], 'weightlifting');
        $this->assertNotContains('one_rm', array_column($lose['prs'], 'type'));
    }

    public function test_distance_in_feet_is_normalized_to_integer_meters(): void
    {
        // 50 ft -> 15 m (parity with the Athlete engine + former LoadOutput strategy).
        $sets = [['distance' => 50, 'distance_unit' => 'ft']];
        $this->assertEquals(15, Reductions::maxOf($sets, 'distance'));
    }

    public function test_mixed_distance_units_are_comparable(): void
    {
        // A distance logged in ft must compare correctly against one logged in m.
        // 60 ft = 18 m must NOT beat a stored 20 m; 70 ft = 21 m must beat it.
        $engine = new PrEngine();
        $lose = $engine->detectPRs(
            $engine->computeMetrics(['liftSets' => [['weight' => 90, 'distance' => 60, 'distance_unit' => 'ft', 'duration' => 30]]], 'load_output'),
            ['distance' => 20],
            'load_output'
        );
        $this->assertNotContains('distance', array_column($lose['prs'], 'type'));

        $win = $engine->detectPRs(
            $engine->computeMetrics(['liftSets' => [['weight' => 90, 'distance' => 70, 'distance_unit' => 'ft', 'duration' => 30]]], 'load_output'),
            ['distance' => 20],
            'load_output'
        );
        $this->assertContains('distance', array_column($win['prs'], 'type'));
    }

    // ─── Comparators ─────────────────────────────────────────────────────────────

    public function test_scalar_best_first_time_is_pr(): void
    {
        $res = Comparators::scalarBest(100, null, ['direction' => 'max', 'tolerance' => 'unit']);
        $this->assertTrue($res['isPR']);
    }

    public function test_scalar_best_respects_unit_tolerance(): void
    {
        $desc = ['direction' => 'max', 'tolerance' => 'unit'];
        $this->assertFalse(Comparators::scalarBest(100.05, 100.0, $desc)['isPR']); // within +0.1 tol
        $this->assertTrue(Comparators::scalarBest(100.2, 100.0, $desc)['isPR']);   // beats +0.1 tol
    }

    public function test_scalar_best_percent_tolerance_for_volume(): void
    {
        $desc = ['direction' => 'max', 'tolerance' => 'percent'];
        // 1% of 1000 = 10; 1005 within tol (not PR), 1011 beats it.
        $this->assertFalse(Comparators::scalarBest(1005, 1000, $desc)['isPR']);
        $this->assertTrue(Comparators::scalarBest(1011, 1000, $desc)['isPR']);
    }

    public function test_scalar_best_min_direction_for_speed(): void
    {
        $desc = ['direction' => 'min', 'tolerance' => 'none'];
        $this->assertTrue(Comparators::scalarBest(45, 60, $desc)['isPR']);  // faster = PR
        $this->assertFalse(Comparators::scalarBest(60, 45, $desc)['isPR']); // slower = not
        $this->assertFalse(Comparators::scalarBest(45, 45, $desc)['isPR']); // equal = not
    }

    public function test_keyed_best_min_direction_speed_bucket(): void
    {
        // Same (weight|distance) bucket, strictly shorter duration is a PR; equal/longer is not.
        $desc = ['direction' => 'min', 'tolerance' => 'none'];
        $res = Comparators::keyedBest(['100|50' => 45], ['100|50' => 60], $desc);
        $this->assertTrue($res['100|50']['isPR']);
        $this->assertEquals(60, $res['100|50']['best']);

        $noRes = Comparators::keyedBest(['100|50' => 60], ['100|50' => 45], $desc);
        $this->assertFalse($noRes['100|50']['isPR']);
    }

    public function test_keyed_best_require_previous_suppresses_first_time(): void
    {
        // density/hypertrophy require a prior entry at the key (no PR the first time it's seen).
        $desc = ['direction' => 'max', 'tolerance' => 'none', 'requirePrevious' => true];
        $first = Comparators::keyedBest(['100' => 3], [], $desc);
        $this->assertFalse($first['100']['isPR']);

        $beat = Comparators::keyedBest(['100' => 4], ['100' => 3], $desc);
        $this->assertTrue($beat['100']['isPR']);
    }

    public function test_keyed_best_suppress_dominated_by_higher_reps(): void
    {
        // A 3-rep weight isn't a rep PR if a >=3-rep set at >= that weight exists (current or history).
        $desc = ['direction' => 'max', 'tolerance' => 'unit', 'suppressDominated' => true];
        // 5 reps @ 210 dominates 3 reps @ 200 in the same session => 3-rep is suppressed.
        $res = Comparators::keyedBest(['3' => 200, '5' => 210], [], $desc);
        $this->assertFalse($res['3']['isPR']);
        $this->assertTrue($res['5']['isPR']);
    }

    // ─── Engine per-family behavior ──────────────────────────────────────────────

    public function test_weightlifting_family_computes_and_detects_core_types(): void
    {
        $engine = new PrEngine();
        $metrics = $engine->computeMetrics(['liftSets' => [['weight' => 200, 'reps' => 5], ['weight' => 220, 'reps' => 3]]], 'weightlifting');
        $this->assertArrayHasKey('one_rm', $metrics);
        $this->assertArrayHasKey('rep_specific', $metrics);
        $this->assertArrayHasKey('volume', $metrics);
        $detected = $engine->detectPRs($metrics, [], 'weightlifting');
        $this->assertContains('one_rm', array_column($detected['prs'], 'type'));
    }

    public function test_rep_specific_pr_can_fire_without_a_1rm_pr(): void
    {
        // 185x5 (est 1RM ~216) does NOT beat a stored 1RM of 218, but IS a 5-rep PR.
        $engine = new PrEngine();
        $metrics = $engine->computeMetrics(['liftSets' => [['weight' => 185, 'reps' => 5]]], 'weightlifting');
        $history = ['one_rm' => 218.0, 'rep_specific' => ['5' => 180.0]];
        $detected = $engine->detectPRs($metrics, $history, 'weightlifting');
        $types = array_column($detected['prs'], 'type');
        $this->assertContains('rep_specific', $types);
        $this->assertNotContains('one_rm', $types);
    }

    public function test_reps_above_ten_are_excluded_from_rep_specific_but_still_score_1rm_and_volume(): void
    {
        // 15-rep sets: rep_specific caps at maxReps=10 (no key), but 1RM (Epley capped) + volume compute.
        $engine = new PrEngine();
        $metrics = $engine->computeMetrics(['liftSets' => [['weight' => 110, 'reps' => 15]]], 'weightlifting');
        $this->assertArrayHasKey('one_rm', $metrics);
        $this->assertArrayHasKey('volume', $metrics);
        // rep_specific either absent or has no key for 15 reps.
        $this->assertArrayNotHasKey('15', $metrics['rep_specific'] ?? []);
    }

    public function test_static_hold_consistency_requires_more_than_one_set(): void
    {
        $engine = new PrEngine();
        // Single set => consistency (minGroupSize 2) does not compute.
        $one = $engine->computeMetrics(['liftSets' => [['time' => 45]]], 'static_hold');
        $this->assertArrayNotHasKey('consistency', $one);
        // Two sets => consistency = min hold.
        $two = $engine->computeMetrics(['liftSets' => [['time' => 45], ['time' => 30]]], 'static_hold');
        $this->assertEquals(30, $two['consistency']);
    }

    public function test_static_hold_density_keys_by_duration_not_weight(): void
    {
        $engine = new PrEngine();
        $metrics = $engine->computeMetrics(['liftSets' => [['time' => 30], ['time' => 30], ['time' => 45]]], 'static_hold');
        // density keyed by time: two sets at 30s, one at 45s.
        $this->assertEquals(['30' => 2, '45' => 1], $metrics['density']);
    }

    public function test_cardio_family_endurance_and_volume(): void
    {
        $engine = new PrEngine();
        $metrics = $engine->computeMetrics(['liftSets' => [['distance' => 500], ['distance' => 700]]], 'cardio');
        $this->assertEquals(700, $metrics['endurance']); // best single
        $this->assertEquals(1200, $metrics['volume']);   // total
    }

    public function test_bodyweight_family_computes_and_detects_volume(): void
    {
        $engine = new PrEngine();
        $metrics = $engine->computeMetrics(['liftSets' => [['weight' => 0, 'reps' => 20]]], 'bodyweight');
        $this->assertEquals(20, $metrics['volume']);
        $detected = $engine->detectPRs($metrics, [], 'bodyweight');
        $this->assertContains('volume', array_column($detected['prs'], 'type'));
    }

    public function test_load_output_speed_fires_only_after_a_prior_bucket(): void
    {
        $engine = new PrEngine();
        $sets = [['weight' => 100, 'distance' => 50, 'distance_unit' => 'm', 'duration' => 45]];
        $metrics = $engine->computeMetrics(['liftSets' => $sets], 'load_output');

        // First time at this bucket, min-direction with no prior => not a PR.
        $first = $engine->detectPRs($metrics, [], 'load_output');
        $this->assertNotContains('speed', array_column($first['prs'], 'type'));

        // Faster at the same bucket => speed PR.
        $beat = $engine->detectPRs($metrics, ['speed' => ['100|50' => 60]], 'load_output');
        $this->assertContains('speed', array_column($beat['prs'], 'type'));
    }

    public function test_banded_family_resolves_to_no_pr_tracking(): void
    {
        $engine = new PrEngine();
        $this->assertNull($engine->resolveFamily('banded'));
        $this->assertNull($engine->resolveFamily('banded_resistance'));
    }

    // ─── "Why not" reasons ───────────────────────────────────────────────────────

    public function test_non_pr_emits_a_structured_reason(): void
    {
        $engine = new PrEngine();
        $metrics = $engine->computeMetrics(['liftSets' => [['weight' => 150, 'reps' => 1]]], 'weightlifting');
        $result = $engine->detectPRs($metrics, ['one_rm' => 300.0], 'weightlifting');

        $this->assertNotContains('one_rm', array_column($result['prs'], 'type'));
        $reason = collect($result['reasons'])->firstWhere('type', 'one_rm');
        $this->assertNotNull($reason);
        $this->assertEquals(150.0, $reason['current']);
        $this->assertEquals(300.0, $reason['best']);
        $this->assertEquals('max', $reason['direction']);
        // deltaToBeat = how far below the best we are.
        $this->assertEqualsWithDelta(150.0, $reason['deltaToBeat'], 0.01);
    }
}
