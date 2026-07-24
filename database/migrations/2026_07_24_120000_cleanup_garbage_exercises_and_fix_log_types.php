<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Cleanup data garbage and fix incorrect log_type values for recently promoted exercises.
 *
 * Data garbage:
 * - "RAW:Plate OH alt. Lunges" (id 228) — unparsed raw entry, has 1 lift log → reassign to plate_oh_alt_lunges (id 229), soft-delete
 * - "user_1782946500996_ivg4oa" (id 243) — broken entry (title = canonical), has 1 lift log → soft-delete (log is empty/useless)
 *
 * LogType corrections:
 * - front_squat_single_arm_1_db: barbell → single-dumbbell
 * - lunge_step_forward_2kb: barbell → dual-kettlebell
 *
 * Fully reversible.
 */
return new class extends Migration
{
    /**
     * Log type corrections: [canonical_name, old_log_type, new_log_type]
     */
    private const LOG_TYPE_FIXES = [
        ['front_squat_single_arm_1_db', 'barbell', 'single-dumbbell'],
        ['lunge_step_forward_2kb', 'barbell', 'dual-kettlebell'],
    ];

    /**
     * Garbage merge: reassign lift_logs from source to target, then soft-delete source.
     * [source_id, target_id]
     */
    private const GARBAGE_MERGE = [
        [228, 229], // "RAW:Plate OH alt. Lunges" → plate_oh_alt_lunges
    ];

    /**
     * Garbage soft-delete only (log is useless data, exercise is broken).
     * [exercise_id]
     */
    private const GARBAGE_DELETE = [
        243, // "user_1782946500996_ivg4oa" — broken entry with empty log
    ];

    public function up(): void
    {
        $now = Carbon::now();

        // ─── LOG TYPE CORRECTIONS ──────────────────────────────────────────
        foreach (self::LOG_TYPE_FIXES as [$canonical, $oldLogType, $newLogType]) {
            DB::table('exercises')
                ->where('canonical_name', $canonical)
                ->whereNull('user_id')
                ->whereNull('deleted_at')
                ->where('log_type', $oldLogType)
                ->update(['log_type' => $newLogType]);
        }

        // ─── GARBAGE MERGES (reassign logs, then soft-delete) ──────────────
        foreach (self::GARBAGE_MERGE as [$sourceId, $targetId]) {
            // Record which log IDs are being moved (for reversibility)
            $movedLogIds = DB::table('lift_logs')
                ->where('exercise_id', $sourceId)
                ->pluck('id')
                ->toArray();

            // Reassign lift_logs from source to target
            DB::table('lift_logs')
                ->where('exercise_id', $sourceId)
                ->update(['exercise_id' => $targetId]);

            // Soft-delete the source, storing moved log IDs for rollback
            DB::table('exercises')
                ->where('id', $sourceId)
                ->update([
                    'deleted_at' => $now,
                    'description' => 'MERGE_LOG_IDS:' . implode(',', $movedLogIds),
                ]);
        }

        // ─── GARBAGE SOFT-DELETES (broken entries) ─────────────────────────
        foreach (self::GARBAGE_DELETE as $exerciseId) {
            DB::table('exercises')
                ->where('id', $exerciseId)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => $now]);
        }
    }

    public function down(): void
    {
        // ─── REVERSE GARBAGE SOFT-DELETES ──────────────────────────────────
        foreach (self::GARBAGE_DELETE as $exerciseId) {
            DB::table('exercises')
                ->where('id', $exerciseId)
                ->whereNotNull('deleted_at')
                ->update(['deleted_at' => null]);
        }

        // ─── REVERSE GARBAGE MERGES ────────────────────────────────────────
        foreach (self::GARBAGE_MERGE as [$sourceId, $targetId]) {
            $source = DB::table('exercises')->where('id', $sourceId)->first();

            if (!$source) {
                continue;
            }

            // Extract stored log IDs
            $logIds = [];
            if ($source->description && str_starts_with($source->description, 'MERGE_LOG_IDS:')) {
                $idsString = substr($source->description, strlen('MERGE_LOG_IDS:'));
                if ($idsString !== '') {
                    $logIds = array_map('intval', explode(',', $idsString));
                }
            }

            // Move logs back to the source exercise
            if (!empty($logIds)) {
                DB::table('lift_logs')
                    ->whereIn('id', $logIds)
                    ->update(['exercise_id' => $sourceId]);
            }

            // Restore the source exercise
            DB::table('exercises')
                ->where('id', $sourceId)
                ->update([
                    'deleted_at' => null,
                    'description' => null,
                ]);
        }

        // ─── REVERSE LOG TYPE CORRECTIONS ──────────────────────────────────
        foreach (self::LOG_TYPE_FIXES as [$canonical, $oldLogType, $newLogType]) {
            DB::table('exercises')
                ->where('canonical_name', $canonical)
                ->whereNull('user_id')
                ->whereNull('deleted_at')
                ->where('log_type', $newLogType)
                ->update(['log_type' => $oldLogType]);
        }
    }
};
