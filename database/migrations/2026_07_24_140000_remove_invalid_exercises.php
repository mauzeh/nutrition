<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Remove exercises that are not real exercises (per manual review).
 *
 * Exercises removed:
 * - "Pike" (id 86) — not a discrete exercise, ambiguous name
 * - "Snatch Grip Behind the Neck Press" (id 192) — not a real exercise
 * - "Lunge (Step-Forward, 2KB, Front Rack)" (id 163) — not a real exercise
 * - "SOTS Press" (id 197) — not a real exercise
 *
 * All associated lift_logs and lift_sets are soft-deleted.
 * Fully reversible via migrate:rollback.
 */
return new class extends Migration
{
    /**
     * Exercises to remove: [exercise_id, canonical_name, log_ids]
     */
    private const EXERCISES = [
        [86, 'pike', [221, 1272]],
        [192, 'snatch_grip_behind_the_neck_press', [1638, 1664]],
        [163, 'lunge_step_forward_2kb', [1260]],
        [197, 'sots_press', [1685]],
    ];

    public function up(): void
    {
        $now = Carbon::now();

        foreach (self::EXERCISES as [$exerciseId, $canonical, $logIds]) {
            // Soft-delete lift_sets for these logs
            if (!empty($logIds)) {
                DB::table('lift_sets')
                    ->whereIn('lift_log_id', $logIds)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now]);

                // Soft-delete lift_logs
                DB::table('lift_logs')
                    ->whereIn('id', $logIds)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now]);
            }

            // Soft-delete the exercise
            DB::table('exercises')
                ->where('id', $exerciseId)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => $now]);
        }
    }

    public function down(): void
    {
        foreach (self::EXERCISES as [$exerciseId, $canonical, $logIds]) {
            // Restore the exercise
            DB::table('exercises')
                ->where('id', $exerciseId)
                ->whereNotNull('deleted_at')
                ->update(['deleted_at' => null]);

            // Restore lift_logs
            if (!empty($logIds)) {
                DB::table('lift_logs')
                    ->whereIn('id', $logIds)
                    ->whereNotNull('deleted_at')
                    ->update(['deleted_at' => null]);

                // Restore lift_sets
                DB::table('lift_sets')
                    ->whereIn('lift_log_id', $logIds)
                    ->whereNotNull('deleted_at')
                    ->update(['deleted_at' => null]);
            }
        }
    }
};
