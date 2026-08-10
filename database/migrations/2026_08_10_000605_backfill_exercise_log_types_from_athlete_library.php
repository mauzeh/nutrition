<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill exercises.log_type using the Athlete exercise library as source of truth.
 *
 * Problem: Some exercises created before the Athlete sync feature have incorrect
 * log_type values (e.g. kettlebell exercises marked as 'barbell'). This causes
 * the RestoreController to return the wrong logType, which breaks unit conversion
 * on the Athlete side (lbs values displayed as kg without conversion).
 *
 * Strategy: Match exercises by canonical_name to the Athlete library's known
 * logType assignments. Only update exercises where the current log_type is wrong.
 */
return new class extends Migration
{
    /**
     * Canonical name → correct log_type, sourced from athlete/src/data/exerciseLibrary.json.
     * Only includes exercises that are NOT the default 'barbell' type, since we're
     * fixing mis-categorized exercises.
     */
    private function getMapping(): array
    {
        return [
            // kettlebell
            'alt_kb_z_press' => 'kettlebell',
            'fr_cossack_squat' => 'kettlebell',
            'get_up' => 'kettlebell',
            'goblet_box_step_up' => 'kettlebell',
            'goblet_curtsy_lunge' => 'kettlebell',
            'goblet_lateral_lunge' => 'kettlebell',
            'goblet_shrimp_squat' => 'kettlebell',
            'gorilla_row' => 'kettlebell',
            'half_kneeling_windmill' => 'kettlebell',
            'kb_around_the_world' => 'kettlebell',
            'kb_bent_over_row' => 'kettlebell',
            'kb_clean_and_press' => 'kettlebell',
            'kb_front_rack_squat' => 'kettlebell',
            'kb_goblet_split_squat' => 'kettlebell',
            'kb_half_kneeling_press' => 'kettlebell',
            'kb_high_pull' => 'kettlebell',
            'kb_horn_curl' => 'kettlebell',
            'kb_horns_up_march' => 'kettlebell',
            'kb_push_press' => 'kettlebell',
            'kb_rdl' => 'kettlebell',
            'kb_side_bend' => 'kettlebell',
            'kb_single_arm_front_squat' => 'kettlebell',
            'kb_single_leg_deadlift' => 'kettlebell',
            'kb_snatch' => 'kettlebell',
            'kb_sumo_deadlift' => 'kettlebell',
            'kb_sumo_deadlift_high_pull' => 'kettlebell',
            'kb_thruster' => 'kettlebell',
            'kb_windmill' => 'kettlebell',
            'kettlebell_deadlift' => 'kettlebell',
            'kettlebell_swing' => 'kettlebell',
            'kettlebell_swings' => 'kettlebell',
            'lateral_goblet_lunges' => 'kettlebell',
            'one_kb_suitcase_deadlift' => 'kettlebell',
            'sa_farmers_carry' => 'kettlebell',
            'seated_kb_press' => 'kettlebell',
            'single_arm_kb_press' => 'kettlebell',
            'single_arm_kb_swing' => 'kettlebell',
            'single_leg_crossbody_kb_rdl' => 'kettlebell',
            'single_leg_kb_rdl' => 'kettlebell',
            'step_back_goblet_lunges' => 'kettlebell',
            'sumo_kb_rdl' => 'kettlebell',
            'turkish_get_up' => 'kettlebell',
            'turkish_situp' => 'kettlebell',
            'two_kb_rdl' => 'kettlebell',

            // dual-kettlebell
            'farmers_carry' => 'dual-kettlebell',
            'farmers_carry_march' => 'dual-kettlebell',
            'two_kb_front_rack_carry' => 'dual-kettlebell',

            // ball
            'ball_slam' => 'ball',
            'dball_to_shoulder' => 'ball',
            'half_kneeling_ball_slam' => 'ball',
            'hanging_leg_raise_med_ball' => 'ball',
            'kneeling_ball_slams' => 'ball',
            'kneeling_rotational_ball_slam' => 'ball',
            'lateral_ball_slam' => 'ball',
            'lateral_medball_toss' => 'ball',
            'lateral_medball_toss_to_wall' => 'ball',
            'med_ball_over_shoulder' => 'ball',
            'med_ball_sit_up_throw' => 'ball',
            'med_ball_wall_throw' => 'ball',
            'medball_hamstring_curl' => 'ball',
            'medball_stir_the_pot' => 'ball',
            'partner_medball_toss' => 'ball',
            'rotational_ball_slam' => 'ball',
            'split_stance_lateral_medball_toss' => 'ball',
            'wall_ball' => 'ball',
        ];
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $mapping = $this->getMapping();

        // Group by target log_type for efficient batch updates
        $byType = [];
        foreach ($mapping as $canonical => $logType) {
            $byType[$logType][] = $canonical;
        }

        foreach ($byType as $logType => $canonicalNames) {
            DB::table('exercises')
                ->whereIn('canonical_name', $canonicalNames)
                ->where(function ($query) use ($logType) {
                    $query->where('log_type', '!=', $logType)
                        ->orWhereNull('log_type');
                })
                ->update(['log_type' => $logType]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Revert to 'barbell' (the previous incorrect default) for any exercises
     * that were updated. This is safe because the only exercises affected are
     * those that had 'barbell' or NULL before this migration ran.
     */
    public function down(): void
    {
        $mapping = $this->getMapping();
        $allCanonicals = array_keys($mapping);

        DB::table('exercises')
            ->whereIn('canonical_name', $allCanonicals)
            ->update(['log_type' => 'barbell']);
    }
};
