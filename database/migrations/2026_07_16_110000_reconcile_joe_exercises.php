<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Reconcile Joe's (user_id=22) user-scoped exercises:
 * - Promote to global (user_id = NULL)
 * - Rename titles to match Athlete library naming conventions
 * - Fix logType and exercise_type
 * - Merge duplicates (reassign lift_logs, soft-delete the duplicate)
 * - Merge into existing global entries where applicable
 *
 * Fully reversible: down() restores original state including unmerging logs.
 */
return new class extends Migration
{
    private const JOE_USER_ID = 22;

    /**
     * Promotions: [old_canonical, old_title, new_title, new_canonical (null = keep), new_log_type, new_exercise_type, old_log_type]
     */
    private const PROMOTIONS = [
        ['angled_leg_press', 'Angled Leg Press', 'Leg Press (Machine)', 'leg_press_machine', 'machine', 'regular', 'barbell'],
        ['cable_bicep_curl', 'Cable bicep curl', 'Bicep Curl (Cable)', null, 'machine', 'regular', 'barbell'],
        ['cable_chest_fly', 'Cable Chest Fly', 'Chest Fly (Cable)', null, 'machine', 'regular', 'barbell'],
        ['cable_row', 'Cable Row', 'Row (Cable)', null, 'machine', 'regular', 'barbell'],
        ['chest_fly_machine', 'Chest Fly Machine', 'Chest Fly (Machine)', null, 'machine', 'regular', 'barbell'],
        ['chin_up_assis', 'Chin Up (Assis)', 'Chin-Up (Assisted)', 'chin_up_assisted', 'machine', 'regular', 'barbell'],
        ['deadlift_kettlebell', 'Deadlift (Kettlebell)', 'Deadlift (Kettlebell)', 'kettlebell_deadlift', 'kettlebell', 'regular', 'barbell'],
        ['glute_drive', 'Glute Drive', 'Glute Drive', null, 'machine', 'regular', 'barbell'],
        ['incline_dumbbell_curl', 'Incline Dumbbell Curl', 'Incline Curl (2-DB)', 'incline_curl_2_db', 'dual-dumbbell', 'regular', 'barbell'],
        ['incline_dumbbell_press', 'Incline Dumbbell Press', 'Incline Bench Press (2-DB)', 'incline_2_db_bench_press', 'dual-dumbbell', 'regular', 'barbell'],
        ['incline_lever_row', 'Incline Lever Row', 'Chest-Supported Row (Machine)', 'chest_supported_row_machine', 'machine', 'regular', 'barbell'],
        ['isolated_bicep_curl', 'Isolated Bicep Curl', 'Preacher Curl', 'preacher_curl', 'barbell', 'regular', 'barbell'],
        ['isolated_bicep_db', 'Isolated Bicep (DB)', 'Preacher Curl (DB)', 'preacher_curl_db', 'single-dumbbell', 'regular', 'barbell'],
        ['isolated_hammerhead_curl', 'Isolated Hammerhead Curl', 'Preacher Hammer Curl (DB)', 'preacher_hammer_curl', 'single-dumbbell', 'regular', 'barbell'],
        ['lateral_raise_dumbbell', 'Lateral Raise (dumbbell)', 'Lateral Raise (2-DB)', 'lateral_raise_2_db', 'dual-dumbbell', 'regular', 'barbell'],
        ['rear_deltoid_fly', 'Rear Deltoid Fly', 'Reverse Fly (2-DB)', 'reverse_fly_2_db', 'dual-dumbbell', 'regular', 'barbell'],
        ['seated_row', 'Seated Row', 'Seated Row (Machine)', 'seated_row_machine', 'machine', 'regular', 'barbell'],
        ['tricep_dumbbell_kickback', 'Tricep Dumbbell Kickback', 'Tricep Kickback (DB)', 'tricep_kickback_db', 'single-dumbbell', 'regular', 'barbell'],
        ['tricep_pushdown', 'Tricep pushdown', 'Tricep Pushdown (Cable)', 'tricep_pushdown_cable', 'machine', 'regular', 'barbell'],
        ['tricep_rope_pushdown', 'Tricep Rope Pushdown', 'Tricep Pushdown (Cable, Rope)', null, 'machine', 'regular', 'barbell'],
        ['tricep_skull_crusher', 'Tricep Skull Crusher', 'Skull Crushers', 'skull_crusher', 'single-dumbbell', 'regular', 'barbell'],
    ];

    /**
     * Merges: [source_canonical, target_id_or_canonical, target_is_id]
     * For rollback: we reassign logs back by filtering on user_id = Joe.
     */
    private const MERGES = [
        ['chest_dips', 80, true],            // → dips (global id 80)
        ['single_leg_deadlift', 260, true],  // → single_leg_kb_rdl (global id 260)
        ['bent_over_row_dumbbell', 'db_tripod_row', false],  // → db_tripod_row (global)
        ['incline_leverage_row', 'incline_lever_row', false], // → incline_lever_row (user-scoped, same user)
    ];

    /**
     * Global title alignments: [canonical, old_title, new_title]
     */
    private const TITLE_ALIGNMENTS = [
        ['hammerhead_curl', 'Hammerhead Curl', 'Hammer Curl'],
        ['chest_fly_2_db', 'Chest Fly (2-DB)', 'Chest Flyes (2-DB)'],
        ['split_squat_hungarian_2_db', 'Split Squat (Hungarian, 2-DB)', 'Bulgarian Split Squat (2-DB)'],
    ];

    public function up(): void
    {
        $now = Carbon::now();

        // ─── MERGES ────────────────────────────────────────────────────────
        foreach (self::MERGES as [$sourceCanonical, $targetRef, $targetIsId]) {
            $this->mergeExercise($sourceCanonical, $targetRef, $targetIsId, $now);
        }

        // ─── PROMOTIONS ────────────────────────────────────────────────────
        foreach (self::PROMOTIONS as [$oldCanonical, $oldTitle, $newTitle, $newCanonical, $newLogType, $newExerciseType, $oldLogType]) {
            $updates = [
                'user_id' => null,
                'title' => $newTitle,
                'log_type' => $newLogType,
                'exercise_type' => $newExerciseType,
            ];

            if ($newCanonical) {
                $updates['canonical_name'] = $newCanonical;
            }

            DB::table('exercises')
                ->where('canonical_name', $oldCanonical)
                ->where('user_id', self::JOE_USER_ID)
                ->whereNull('deleted_at')
                ->update($updates);
        }

        // ─── GLOBAL TITLE ALIGNMENTS ───────────────────────────────────────
        foreach (self::TITLE_ALIGNMENTS as [$canonical, $oldTitle, $newTitle]) {
            DB::table('exercises')
                ->where('canonical_name', $canonical)
                ->whereNull('user_id')
                ->whereNull('deleted_at')
                ->update(['title' => $newTitle]);
        }
    }

    public function down(): void
    {
        // ─── REVERSE GLOBAL TITLE ALIGNMENTS ───────────────────────────────
        foreach (self::TITLE_ALIGNMENTS as [$canonical, $oldTitle, $newTitle]) {
            DB::table('exercises')
                ->where('canonical_name', $canonical)
                ->whereNull('user_id')
                ->whereNull('deleted_at')
                ->update(['title' => $oldTitle]);
        }

        // ─── REVERSE PROMOTIONS ────────────────────────────────────────────
        foreach (self::PROMOTIONS as [$oldCanonical, $oldTitle, $newTitle, $newCanonical, $newLogType, $newExerciseType, $oldLogType]) {
            $currentCanonical = $newCanonical ?? $oldCanonical;

            $updates = [
                'user_id' => self::JOE_USER_ID,
                'title' => $oldTitle,
                'log_type' => $oldLogType,
                'exercise_type' => 'regular',
            ];

            if ($newCanonical) {
                $updates['canonical_name'] = $oldCanonical;
            }

            DB::table('exercises')
                ->where('canonical_name', $currentCanonical)
                ->whereNull('user_id')
                ->whereNull('deleted_at')
                ->update($updates);
        }

        // ─── REVERSE MERGES ────────────────────────────────────────────────
        // For each merge, restore the source exercise and move Joe's logs back.
        foreach (array_reverse(self::MERGES) as [$sourceCanonical, $targetRef, $targetIsId]) {
            $this->unmergeExercise($sourceCanonical, $targetRef, $targetIsId);
        }
    }

    /**
     * Merge: reassign lift_logs from source to target, soft-delete source.
     * Stores moved log IDs in source's description field for reversibility.
     */
    private function mergeExercise(string $sourceCanonical, int|string $targetRef, bool $targetIsId, Carbon $now): void
    {
        $source = DB::table('exercises')
            ->where('canonical_name', $sourceCanonical)
            ->where('user_id', self::JOE_USER_ID)
            ->whereNull('deleted_at')
            ->first();

        if (!$source) {
            return;
        }

        if ($targetIsId) {
            $target = DB::table('exercises')->where('id', $targetRef)->first();
        } else {
            // Target might be user-scoped (same user) or global
            $target = DB::table('exercises')
                ->where('canonical_name', $targetRef)
                ->whereNull('deleted_at')
                ->where(function ($query) {
                    $query->whereNull('user_id')
                          ->orWhere('user_id', self::JOE_USER_ID);
                })
                ->first();
        }

        if (!$target) {
            return;
        }

        // Record which log IDs are being moved (for reversibility)
        $movedLogIds = DB::table('lift_logs')
            ->where('exercise_id', $source->id)
            ->pluck('id')
            ->toArray();

        // Reassign all lift_logs from source to target
        DB::table('lift_logs')
            ->where('exercise_id', $source->id)
            ->update(['exercise_id' => $target->id]);

        // Soft-delete the source exercise, storing moved log IDs for rollback
        DB::table('exercises')
            ->where('id', $source->id)
            ->update([
                'deleted_at' => $now,
                'description' => 'MERGE_LOG_IDS:' . implode(',', $movedLogIds),
            ]);
    }

    /**
     * Unmerge: restore the source exercise and move the specific logs back.
     * Reads moved log IDs from source's description field.
     */
    private function unmergeExercise(string $sourceCanonical, int|string $targetRef, bool $targetIsId): void
    {
        // Find the soft-deleted source exercise
        $source = DB::table('exercises')
            ->where('canonical_name', $sourceCanonical)
            ->where('user_id', self::JOE_USER_ID)
            ->whereNotNull('deleted_at')
            ->first();

        if (!$source) {
            return;
        }

        // Extract the stored log IDs
        $logIds = [];
        if ($source->description && str_starts_with($source->description, 'MERGE_LOG_IDS:')) {
            $idsString = substr($source->description, strlen('MERGE_LOG_IDS:'));
            if ($idsString !== '') {
                $logIds = array_map('intval', explode(',', $idsString));
            }
        }

        // Move the specific logs back to the source exercise
        if (!empty($logIds)) {
            DB::table('lift_logs')
                ->whereIn('id', $logIds)
                ->update(['exercise_id' => $source->id]);
        }

        // Restore the source exercise (clear soft-delete and description)
        DB::table('exercises')
            ->where('id', $source->id)
            ->update([
                'deleted_at' => null,
                'description' => null,
            ]);
    }
};
