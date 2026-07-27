<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Reconcile user-created exercises (user_id=1) into canonical library exercises.
 *
 * Operations:
 * 1. bottom_up_front_squat (id=177): reassign 1 lift_log to front_squat (id=13), soft-delete exercise
 * 2. hspu_on_2_db_50lbs (id=193): reassign 7 lift_logs to deficit_hspu (id=255), soft-delete exercise
 * 3. hpsu_tempo_negative_50lbs_db (id=189): reassign 1 lift_log to deficit_hspu (id=255), soft-delete exercise
 * 4. power_clean_drills (id=57): reassign 3 lift_logs to power_clean (id=5), soft-delete exercise
 *
 * All operations are fully reversible via migrate:rollback.
 */
return new class extends Migration
{
    /**
     * [source_exercise_id, target_exercise_id, lift_log_ids]
     */
    private const MERGES = [
        [177, 13, [1472]],         // bottom_up_front_squat → front_squat
        [193, 255, [1641, 1665, 1667, 1705, 1752, 1772, 1840]], // hspu_on_2_db_50lbs → deficit_hspu
        [189, 255, [1600]],        // hpsu_tempo_negative_50lbs_db → deficit_hspu
        [57, 5, [153, 199, 233]],  // power_clean_drills → power_clean
    ];

    public function up(): void
    {
        $now = Carbon::now();

        foreach (self::MERGES as [$sourceId, $targetId, $logIds]) {
            // Reassign lift_logs to the target exercise
            DB::table('lift_logs')
                ->where('exercise_id', $sourceId)
                ->whereIn('id', $logIds)
                ->update(['exercise_id' => $targetId]);

            // Soft-delete the source exercise
            DB::table('exercises')
                ->where('id', $sourceId)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => $now]);
        }
    }

    public function down(): void
    {
        foreach (self::MERGES as [$sourceId, $targetId, $logIds]) {
            // Restore the source exercise
            DB::table('exercises')
                ->where('id', $sourceId)
                ->whereNotNull('deleted_at')
                ->update(['deleted_at' => null]);

            // Reassign lift_logs back to the source exercise
            DB::table('lift_logs')
                ->where('exercise_id', $targetId)
                ->whereIn('id', $logIds)
                ->update(['exercise_id' => $sourceId]);
        }
    }
};
