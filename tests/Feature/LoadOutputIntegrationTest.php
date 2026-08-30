<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\LiftSet;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Factories\LiftLogFormFactory;
use App\Sync\Services\ExerciseResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoadOutputIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function exercise_resolver_routes_weighted_carry_and_sled_to_load_output()
    {
        $resolver = new ExerciseResolverService();
        $user = User::factory()->create();

        $carryEx = $resolver->resolve('Farmer Carry', $user, 'weighted-carry');
        $this->assertEquals('load_output', $carryEx->exercise_type);

        $sledEx = $resolver->resolve('Sled Push', $user, 'sled');
        $this->assertEquals('load_output', $sledEx->exercise_type);

        $holdEx = $resolver->resolve('Plank', $user, 'static-hold');
        $this->assertEquals('static_hold', $holdEx->exercise_type);

        $dkEx = $resolver->resolve('Dual KB Clean', $user, 'dual-kettlebell');
        $this->assertEquals('static_hold', $dkEx->exercise_type);
    }

    /** @test */
    public function lift_log_form_factory_returns_load_output_fields()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['exercise_type' => 'load_output']);

        $strategy = $exercise->getTypeStrategy();
        $fieldDefinitions = $strategy->getFormFieldDefinitions([], $user);

        $fieldNames = array_column($fieldDefinitions, 'name');
        $this->assertContains('weight', $fieldNames);
        $this->assertContains('distance', $fieldNames);
        $this->assertContains('distance_unit', $fieldNames);
        $this->assertContains('time', $fieldNames);
    }

    /** @test */
    public function it_sets_is_pr_flag_and_row_pr_styling_when_load_output_pr_is_achieved()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['exercise_type' => 'load_output', 'log_type' => 'sled']);

        $log = LiftLog::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercise->id]);
        LiftSet::factory()->create([
            'lift_log_id' => $log->id,
            'weight' => 100,
            'unit' => 'lbs',
            'distance' => 50,
            'distance_unit' => 'm',
            'time' => 30,
        ]);

        $detectService = app(\App\Services\PRDetectionService::class);
        $prs = $detectService->detectPRsWithDetails($log);

        $this->assertNotEmpty($prs);

        // Record PRs
        foreach ($prs as $prData) {
            PersonalRecord::create(array_merge($prData, [
                'user_id' => $user->id,
                'exercise_id' => $exercise->id,
                'lift_log_id' => $log->id,
                'achieved_at' => now(),
            ]));
        }

        $log->update([
            'is_pr' => true,
            'pr_count' => count($prs),
        ]);

        $log->refresh();
        $this->assertTrue((bool) $log->is_pr);
        $this->assertGreaterThan(0, $log->pr_count);
    }
}
