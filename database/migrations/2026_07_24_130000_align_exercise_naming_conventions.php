<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Align global exercise titles and canonicals with Athlete library naming conventions.
 *
 * Changes:
 * 1. Title renames — pluralization, equipment qualifiers, abbreviations, punctuation
 * 2. Canonical renames — user-generated IDs → proper snake_case
 * 3. Merges — duplicate Chest-Supported Row (Barbell) entries consolidated
 *
 * Conventions enforced (per athlete/docs/schemas/exercise-library-schema.md):
 * - Equipment in parentheses as suffix: "Movement (Equipment)"
 * - Snatches always include equipment qualifier
 * - Use "&" not "and"
 * - Use "Overhead" not "OH"
 * - Compound modifiers hyphenated: "Chest-Supported"
 * - IDs in snake_case
 * - Pluralization aligns with Athlete library
 *
 * Fully reversible.
 */
return new class extends Migration
{
    /**
     * Title renames: [canonical_name, old_title, new_title]
     * (exercises identified by current canonical)
     */
    private const TITLE_RENAMES = [
        ['box_jump', 'Box Jump', 'Box Jumps'],
        ['wall_ball', 'Wall Ball', 'Wall Balls'],
        ['clean_and_jerk', 'Clean and Jerk', 'Clean & Jerk'],
        ['snatch', 'Snatch', 'Snatches (Barbell)'],
        ['power_snatch', 'Power Snatch', 'Power Snatches (Barbell)'],
        ['hang_power_snatch', 'Hang Power Snatch', 'Hang Power Snatch (Barbell)'],
        ['snatch_balance', 'Snatch Balance', 'Snatch Balance (Barbell)'],
        ['plate_oh_alt_lunges', 'OH Lunge (Plate, Alt.)', 'Overhead Lunges (Plate)'],
    ];

    /**
     * Canonical + title renames: [old_canonical, new_canonical, old_title, new_title]
     */
    private const CANONICAL_RENAMES = [
        ['user_1782962396762_mdng8r', 'feet_elevated_glute_bridge_db', 'Feet Elevated Glute Bridge (DB)', 'Feet Elevated Glute Bridge (DB)'],
        ['user_1783457645085_w9z83r', 'kipping_hspu_wall', 'Kipping HSPU (Wall)', 'Kipping HSPU (Wall)'],
        ['user_1783106446570_2p8huh', 'l_sit_tucked_parallettes', 'L-Sit (Tucked, Paralettes)', 'L-Sit (Tucked, Parallettes)'],
    ];

    /**
     * Merge: consolidate two Chest-Supported Row (Barbell) duplicates.
     *
     * Strategy: Rename exercise 247 (already has correct hyphenated title) to the
     * proper canonical, merge exercise 240's logs into it, then soft-delete 240.
     *
     * - Exercise 247: user_1782963279292_2saiwg → chest_supported_row_barbell (becomes the canonical)
     * - Exercise 240: user_1782933673968_fvfg22 → merge logs into 247, soft-delete
     */
    private const MERGE_TARGET_ID = 247;
    private const MERGE_TARGET_OLD_CANONICAL = 'user_1782963279292_2saiwg';
    private const MERGE_TARGET_NEW_CANONICAL = 'chest_supported_row_barbell';
    private const MERGE_TARGET_TITLE = 'Chest-Supported Row (Barbell)';

    private const MERGE_SOURCE_ID = 240;
    private const MERGE_SOURCE_OLD_CANONICAL = 'user_1782933673968_fvfg22';
    private const MERGE_SOURCE_OLD_TITLE = 'Chest Supported Row (Barbell)';

    public function up(): void
    {
        $now = Carbon::now();

        // ─── TITLE RENAMES ─────────────────────────────────────────────────
        foreach (self::TITLE_RENAMES as [$canonical, $oldTitle, $newTitle]) {
            DB::table('exercises')
                ->where('canonical_name', $canonical)
                ->whereNull('user_id')
                ->whereNull('deleted_at')
                ->update(['title' => $newTitle]);
        }

        // ─── CANONICAL + TITLE RENAMES ─────────────────────────────────────
        foreach (self::CANONICAL_RENAMES as [$oldCanonical, $newCanonical, $oldTitle, $newTitle]) {
            $updates = ['canonical_name' => $newCanonical];
            if ($oldTitle !== $newTitle) {
                $updates['title'] = $newTitle;
            }

            DB::table('exercises')
                ->where('canonical_name', $oldCanonical)
                ->whereNull('user_id')
                ->whereNull('deleted_at')
                ->update($updates);
        }

        // ─── MERGE: Chest-Supported Row (Barbell) duplicates ───────────────

        // Step 1: Rename the target (247) to proper canonical
        DB::table('exercises')
            ->where('id', self::MERGE_TARGET_ID)
            ->update(['canonical_name' => self::MERGE_TARGET_NEW_CANONICAL]);

        // Step 2: Record which logs will be moved from source (240)
        $movedLogIds = DB::table('lift_logs')
            ->where('exercise_id', self::MERGE_SOURCE_ID)
            ->pluck('id')
            ->toArray();

        // Step 3: Reassign source's logs to target
        if (!empty($movedLogIds)) {
            DB::table('lift_logs')
                ->whereIn('id', $movedLogIds)
                ->update(['exercise_id' => self::MERGE_TARGET_ID]);
        }

        // Step 4: Soft-delete source, store moved log IDs for rollback
        DB::table('exercises')
            ->where('id', self::MERGE_SOURCE_ID)
            ->update([
                'deleted_at' => $now,
                'description' => 'MERGE_LOG_IDS:' . implode(',', $movedLogIds),
            ]);
    }

    public function down(): void
    {
        // ─── REVERSE MERGE ─────────────────────────────────────────────────

        // Step 1: Restore source exercise (240)
        $source = DB::table('exercises')->where('id', self::MERGE_SOURCE_ID)->first();

        if ($source) {
            $logIds = [];
            if ($source->description && str_starts_with($source->description, 'MERGE_LOG_IDS:')) {
                $idsString = substr($source->description, strlen('MERGE_LOG_IDS:'));
                if ($idsString !== '') {
                    $logIds = array_map('intval', explode(',', $idsString));
                }
            }

            // Move logs back to source
            if (!empty($logIds)) {
                DB::table('lift_logs')
                    ->whereIn('id', $logIds)
                    ->update(['exercise_id' => self::MERGE_SOURCE_ID]);
            }

            // Restore source exercise
            DB::table('exercises')
                ->where('id', self::MERGE_SOURCE_ID)
                ->update([
                    'deleted_at' => null,
                    'description' => null,
                ]);
        }

        // Step 2: Restore target's old canonical
        DB::table('exercises')
            ->where('id', self::MERGE_TARGET_ID)
            ->update(['canonical_name' => self::MERGE_TARGET_OLD_CANONICAL]);

        // ─── REVERSE CANONICAL + TITLE RENAMES ─────────────────────────────
        foreach (self::CANONICAL_RENAMES as [$oldCanonical, $newCanonical, $oldTitle, $newTitle]) {
            $updates = ['canonical_name' => $oldCanonical];
            if ($oldTitle !== $newTitle) {
                $updates['title'] = $oldTitle;
            }

            DB::table('exercises')
                ->where('canonical_name', $newCanonical)
                ->whereNull('user_id')
                ->whereNull('deleted_at')
                ->update($updates);
        }

        // ─── REVERSE TITLE RENAMES ─────────────────────────────────────────
        foreach (self::TITLE_RENAMES as [$canonical, $oldTitle, $newTitle]) {
            DB::table('exercises')
                ->where('canonical_name', $canonical)
                ->whereNull('user_id')
                ->whereNull('deleted_at')
                ->update(['title' => $oldTitle]);
        }
    }
};
