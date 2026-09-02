<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\LiftLogTableRowBuilder\PRRecordsComponentAssembler;
use App\Services\LiftLogTableRowBuilder\RowConfig;
use App\Services\PRDetectionService;
use App\Services\PRRecalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BodyweightVolumeSplitTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Exercise $dips;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->dips = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Dips',
            'exercise_type' => 'bodyweight',
            'log_type' => 'bodyweight-reps',
        ]);
    }

    public function test_dips_anchor_replay(): void
    {
        // 1. Prior pure-BW session (best 24)
        $log0 = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->dips->id,
            'logged_at' => now()->subDays(3),
        ]);
        $log0->liftSets()->create(['weight' => 0, 'reps' => 24, 'unit' => 'lbs']);
        app(PRRecalculationService::class)->recalculateAllPRsForExercise($this->user->id, $this->dips->id);

        // 2. Weighted 25 lbs x 6 x 4 (volume = 600)
        $log1 = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->dips->id,
            'logged_at' => now()->subDays(2),
        ]);
        foreach (range(1, 4) as $i) {
            $log1->liftSets()->create(['weight' => 25, 'reps' => 6, 'unit' => 'lbs']);
        }
        app(PRRecalculationService::class)->recalculateAllPRsForExercise($this->user->id, $this->dips->id);

        // 3. Pure-BW 5, 7, 8, 8 (reps = 28)
        $log2 = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->dips->id,
            'logged_at' => now()->subDays(1),
        ]);
        foreach ([5, 7, 8, 8] as $reps) {
            $log2->liftSets()->create(['weight' => 0, 'reps' => $reps, 'unit' => 'lbs']);
        }
        app(PRRecalculationService::class)->recalculateAllPRsForExercise($this->user->id, $this->dips->id);

        // Assert log2 fires bodyweight_volume PR (value 28, previous 24), but NO volume PR
        $prs = PersonalRecord::where('lift_log_id', $log2->id)->get();
        $this->assertCount(1, $prs);
        $bwPr = $prs->first();
        $this->assertEquals('bodyweight_volume', $bwPr->pr_type);
        $this->assertEquals(28, $bwPr->value);
        $this->assertEquals(24, $bwPr->previous_value);

        // Confirm weighted volume PR from log1 (600) still exists and is current
        $volumePr = PersonalRecord::where('exercise_id', $this->dips->id)
            ->where('pr_type', 'volume')
            ->current()
            ->first();
        $this->assertNotNull($volumePr);
        $this->assertEquals(600, $volumePr->value);
    }

    public function test_chain_isolation(): void
    {
        // Interleaved weighted and bodyweight logs
        $log1 = LiftLog::factory()->create(['user_id' => $this->user->id, 'exercise_id' => $this->dips->id, 'logged_at' => now()->subDays(4)]);
        $log1->liftSets()->create(['weight' => 0, 'reps' => 20, 'unit' => 'lbs']);

        $log2 = LiftLog::factory()->create(['user_id' => $this->user->id, 'exercise_id' => $this->dips->id, 'logged_at' => now()->subDays(3)]);
        $log2->liftSets()->create(['weight' => 25, 'reps' => 10, 'unit' => 'lbs']);

        $log3 = LiftLog::factory()->create(['user_id' => $this->user->id, 'exercise_id' => $this->dips->id, 'logged_at' => now()->subDays(2)]);
        $log3->liftSets()->create(['weight' => 0, 'reps' => 25, 'unit' => 'lbs']);

        $log4 = LiftLog::factory()->create(['user_id' => $this->user->id, 'exercise_id' => $this->dips->id, 'logged_at' => now()->subDays(1)]);
        $log4->liftSets()->create(['weight' => 25, 'reps' => 12, 'unit' => 'lbs']);

        app(PRRecalculationService::class)->recalculateAllPRsForExercise($this->user->id, $this->dips->id);

        $currentVolume = PersonalRecord::where('exercise_id', $this->dips->id)->where('pr_type', 'volume')->current()->first();
        $currentBwVolume = PersonalRecord::where('exercise_id', $this->dips->id)->where('pr_type', 'bodyweight_volume')->current()->first();

        // Both chains must have their own independent current record!
        $this->assertNotNull($currentVolume);
        $this->assertEquals($log4->id, $currentVolume->lift_log_id);
        $this->assertEquals(300, $currentVolume->value);

        $this->assertNotNull($currentBwVolume);
        $this->assertEquals($log3->id, $currentBwVolume->lift_log_id);
        $this->assertEquals(25, $currentBwVolume->value);
    }

    public function test_display_assembler_renders_correct_labels(): void
    {
        $priorLog = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->dips->id,
            'logged_at' => now()->subDay(),
        ]);
        $priorLog->liftSets()->create(['weight' => 0, 'reps' => 10, 'unit' => 'lbs']);

        $log = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->dips->id,
            'is_pr' => true,
            'logged_at' => now(),
        ]);
        $log->liftSets()->create(['weight' => 25, 'reps' => 6, 'unit' => 'lbs']);

        $weightedPr = PersonalRecord::create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->dips->id,
            'lift_log_id' => $log->id,
            'pr_type' => 'volume',
            'value' => 600,
            'unit' => 'lbs',
            'achieved_at' => now(),
        ]);

        $bwPr = PersonalRecord::create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->dips->id,
            'lift_log_id' => $log->id,
            'pr_type' => 'bodyweight_volume',
            'value' => 28,
            'unit' => 'lbs',
            'achieved_at' => now(),
        ]);

        $components = PRRecordsComponentAssembler::assemble($log, new RowConfig());
        $json = json_encode($components);

        // Weighted volume renders "Volume", never "600 reps"
        $this->assertStringContainsString('Volume', $json);
        $this->assertStringNotContainsString('600 reps', $json);

        // bodyweight_volume renders "Total Reps" with reps format
        $this->assertStringContainsString('Total Reps', $json);
        $this->assertStringContainsString('28 reps', $json);
    }

    public function test_kg_pure_bodyweight_value_is_not_converted(): void
    {
        $log = LiftLog::factory()->create([
            'user_id' => $this->user->id,
            'exercise_id' => $this->dips->id,
        ]);
        $log->liftSets()->create(['weight' => 0, 'reps' => 25, 'unit' => 'kg']);

        $prs = app(PRDetectionService::class)->detectPRsWithDetails($log);
        $bwPr = collect($prs)->firstWhere('type', 'bodyweight_volume');

        $this->assertNotNull($bwPr);
        $this->assertEquals(25, $bwPr['value']);
    }
}
