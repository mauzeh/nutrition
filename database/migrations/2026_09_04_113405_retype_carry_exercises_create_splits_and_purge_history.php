<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();

        // Map canonical_name -> [new_log_type, title]
        $retypeMap = [
            'farmers_carry' => ['weighted-carry-2-kb', "Farmer's Carry"],
            'farmers_carry_march' => ['weighted-carry-2-kb', "Farmer's Carry March"],
            'two_kb_front_rack_carry' => ['weighted-carry-2-kb', 'Two KB Front Rack Carry'],
            'kb_rack_carry' => ['weighted-carry-2-kb', 'KB Rack Carry'],

            'sa_farmers_carry' => ['weighted-carry-1-kb', "Single-Arm Farmer's Carry"],
            'kb_horns_up_march' => ['weighted-carry-1-kb', 'KB Horns-Up March'],
            'bottoms_up_kb_carry' => ['weighted-carry-1-kb', 'Bottoms-Up KB Carry'],
            'filly_kb_carry' => ['weighted-carry-1-kb', 'Filly KB Carry'],
            'kb_overhead_carry' => ['weighted-carry-1-kb', 'KB Overhead Carry'],
            'kb_bottoms_up_hold_walk' => ['weighted-carry-1-kb', 'KB Bottoms-Up Hold Walk'],

            'bearhug_carry' => ['weighted-carry-ball', 'Bear Hug Carry'],
            'bear_hug_march' => ['weighted-carry-ball', 'Bear Hug March'],
        ];

        // 1. Re-type existing carry defs scoped by canonical_name (FROZEN §4 non-split rows)
        foreach ($retypeMap as $canonical => [$newLogType, $title]) {
            DB::table('exercises')
                ->where('canonical_name', $canonical)
                ->update([
                    'log_type' => $newLogType,
                    'exercise_type' => 'load_output',
                    'updated_at' => $now,
                ]);

            // Update dependent lift_logs.log_type
            DB::table('lift_logs')
                ->whereIn('exercise_id', function ($query) use ($canonical) {
                    $query->select('id')
                        ->from('exercises')
                        ->where('canonical_name', $canonical);
                })
                ->update([
                    'log_type' => $newLogType,
                    'updated_at' => $now,
                ]);
        }

        // 2. Create the six split defs
        $splitDefs = [
            [
                'title' => 'Mixed Rack Carry (KB)',
                'canonical_name' => 'mixed_rack_carry_kb',
                'exercise_type' => 'load_output',
                'log_type' => 'weighted-carry-2-kb',
                'user_id' => null,
                'show_in_feed' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Mixed Rack Carry (DB)',
                'canonical_name' => 'mixed_rack_carry_db',
                'exercise_type' => 'load_output',
                'log_type' => 'weighted-carry-2-db',
                'user_id' => null,
                'show_in_feed' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Single-Arm Overhead Carry (KB)',
                'canonical_name' => 'single_arm_oh_carry_kb',
                'exercise_type' => 'load_output',
                'log_type' => 'weighted-carry-1-kb',
                'user_id' => null,
                'show_in_feed' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Single-Arm Overhead Carry (DB)',
                'canonical_name' => 'single_arm_oh_carry_db',
                'exercise_type' => 'load_output',
                'log_type' => 'weighted-carry-1-db',
                'user_id' => null,
                'show_in_feed' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Suitcase March (KB)',
                'canonical_name' => 'suitcase_march_kb',
                'exercise_type' => 'load_output',
                'log_type' => 'weighted-carry-1-kb',
                'user_id' => null,
                'show_in_feed' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Suitcase March (DB)',
                'canonical_name' => 'suitcase_march_db',
                'exercise_type' => 'load_output',
                'log_type' => 'weighted-carry-1-db',
                'user_id' => null,
                'show_in_feed' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($splitDefs as $def) {
            DB::table('exercises')->updateOrInsert(
                ['canonical_name' => $def['canonical_name']],
                $def
            );
        }

        // 3. PURGE (soft-delete) all lift_logs + lift_sets + personal_records for all affected + retired canonical_names
        $allPurgedCanonicals = array_merge(
            array_keys($retypeMap),
            ['mixed_rack_carry', 'single_arm_oh_carry', 'suitcase_march']
        );

        $affectedExerciseIds = DB::table('exercises')
            ->whereIn('canonical_name', $allPurgedCanonicals)
            ->pluck('id')
            ->toArray();

        if (! empty($affectedExerciseIds)) {
            $affectedLogIds = DB::table('lift_logs')
                ->whereIn('exercise_id', $affectedExerciseIds)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->toArray();

            // Soft-delete personal_records for affected exercises
            DB::table('personal_records')
                ->whereIn('exercise_id', $affectedExerciseIds)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => $now]);

            if (! empty($affectedLogIds)) {
                // Soft-delete lift_sets for affected lift_logs
                DB::table('lift_sets')
                    ->whereIn('lift_log_id', $affectedLogIds)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now]);

                // Soft-delete lift_logs
                DB::table('lift_logs')
                    ->whereIn('id', $affectedLogIds)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $now = now();

        // Restore re-typed rows' exercise_type and log_type back to static_hold / dual-kettlebell or weighted-carry
        $revertMap = [
            'farmers_carry' => ['log_type' => 'weighted-carry', 'exercise_type' => 'static_hold'],
            'farmers_carry_march' => ['log_type' => 'weighted-carry', 'exercise_type' => 'static_hold'],
            'two_kb_front_rack_carry' => ['log_type' => 'dual-kettlebell', 'exercise_type' => 'static_hold'],
            'kb_rack_carry' => ['log_type' => 'dual-kettlebell', 'exercise_type' => 'static_hold'],
            'sa_farmers_carry' => ['log_type' => 'weighted-carry', 'exercise_type' => 'static_hold'],
            'kb_horns_up_march' => ['log_type' => 'dual-kettlebell', 'exercise_type' => 'static_hold'],
            'bottoms_up_kb_carry' => ['log_type' => 'weighted-carry', 'exercise_type' => 'static_hold'],
            'filly_kb_carry' => ['log_type' => 'dual-kettlebell', 'exercise_type' => 'static_hold'],
            'kb_overhead_carry' => ['log_type' => 'weighted-carry', 'exercise_type' => 'static_hold'],
            'kb_bottoms_up_hold_walk' => ['log_type' => 'weighted-carry', 'exercise_type' => 'static_hold'],
            'bearhug_carry' => ['log_type' => 'weighted-carry', 'exercise_type' => 'static_hold'],
            'bear_hug_march' => ['log_type' => 'weighted-carry', 'exercise_type' => 'static_hold'],
        ];

        foreach ($revertMap as $canonical => $attrs) {
            DB::table('exercises')
                ->where('canonical_name', $canonical)
                ->update([
                    'log_type' => $attrs['log_type'],
                    'exercise_type' => $attrs['exercise_type'],
                    'updated_at' => $now,
                ]);

            DB::table('lift_logs')
                ->whereIn('exercise_id', function ($query) use ($canonical) {
                    $query->select('id')
                        ->from('exercises')
                        ->where('canonical_name', $canonical);
                })
                ->update([
                    'log_type' => $attrs['log_type'],
                    'updated_at' => $now,
                ]);
        }

        // Delete created split definitions
        DB::table('exercises')
            ->whereIn('canonical_name', [
                'mixed_rack_carry_kb',
                'mixed_rack_carry_db',
                'single_arm_oh_carry_kb',
                'single_arm_oh_carry_db',
                'suitcase_march_kb',
                'suitcase_march_db',
            ])
            ->delete();

        // Restore soft-deleted history for affected canonicals
        $allPurgedCanonicals = array_merge(
            array_keys($revertMap),
            ['mixed_rack_carry', 'single_arm_oh_carry', 'suitcase_march']
        );

        $affectedExerciseIds = DB::table('exercises')
            ->whereIn('canonical_name', $allPurgedCanonicals)
            ->pluck('id')
            ->toArray();

        if (! empty($affectedExerciseIds)) {
            DB::table('personal_records')
                ->whereIn('exercise_id', $affectedExerciseIds)
                ->whereNotNull('deleted_at')
                ->update(['deleted_at' => null]);

            $affectedLogIds = DB::table('lift_logs')
                ->whereIn('exercise_id', $affectedExerciseIds)
                ->whereNotNull('deleted_at')
                ->pluck('id')
                ->toArray();

            if (! empty($affectedLogIds)) {
                DB::table('lift_sets')
                    ->whereIn('lift_log_id', $affectedLogIds)
                    ->whereNotNull('deleted_at')
                    ->update(['deleted_at' => null]);

                DB::table('lift_logs')
                    ->whereIn('id', $affectedLogIds)
                    ->whereNotNull('deleted_at')
                    ->update(['deleted_at' => null]);
            }
        }
    }
};
