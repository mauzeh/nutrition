<?php

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Services\PRRecalculationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration B: Re-type historical sled and carry exercises to load_output,
 * recompute affected PRs via PRRecalculationService, assert zero sled_* records,
 * and drop sled_* values from personal_records.pr_type enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Update exercises scoped strictly by log_type IN ('sled', 'weighted-carry')
        $affectedExercises = Exercise::whereIn('log_type', ['sled', 'weighted-carry'])->get();

        foreach ($affectedExercises as $exercise) {
            $exercise->update(['exercise_type' => 'load_output']);
        }

        \App\Services\ExerciseTypes\ExerciseTypeFactory::clearCache();

        // 2. Break self-referential previous_pr_id links that point AT sled_* rows before any
        //    delete. personal_records.previous_pr_id is a self-FK with ON DELETE NO ACTION, so
        //    MySQL checks it row-by-row mid-statement: deleting a parent sled_* row before its
        //    child within the same DELETE transiently violates the constraint and the whole delete
        //    fails. Nulling these references first lets the per-exercise recompute delete the rows.
        //    (Recompute regenerates the PRs anyway, so previous_pr_id linkage on old rows is moot.)
        $sledPrIds = DB::table('personal_records')
            ->whereIn('pr_type', ['sled_weight', 'sled_distance', 'sled_volume'])
            ->pluck('id');

        if ($sledPrIds->isNotEmpty()) {
            DB::table('personal_records')
                ->whereIn('previous_pr_id', $sledPrIds)
                ->update(['previous_pr_id' => null]);
        }

        // 3. Recompute PRs for all users of affected exercises. PRRecalculationService deletes ALL
        //    PRs for each (user, exercise) then rebuilds under the new load_output strategy — this
        //    clears the legacy sled_* rows (now unreferenced) and regenerates load/distance/duration/
        //    speed rows. Do NOT add a blanket DELETE WHERE pr_type IN (sled_*): it violates the
        //    self-FK for the same reason as above.
        $recalculator = app(PRRecalculationService::class);
        foreach ($affectedExercises as $exercise) {
            $userIds = DB::table('lift_logs')
                ->where('exercise_id', $exercise->id)
                ->distinct()
                ->pluck('user_id');

            foreach ($userIds as $userId) {
                $recalculator->recalculateAllPRsForExercise($userId, $exercise->id);
            }
        }

        // 4. Hard-delete any remaining sled_* rows. PersonalRecord uses SoftDeletes, so the
        //    recompute's Eloquent ->delete() only set deleted_at on the old sled_* rows — they are
        //    still physically present (and still carry the sled_* enum value we're about to drop).
        //    A raw query-builder delete bypasses the soft-delete scope and physically removes them.
        //    Their inbound previous_pr_id refs were nulled in step 2, so this won't hit the self-FK.
        DB::table('personal_records')
            ->whereIn('pr_type', ['sled_weight', 'sled_distance', 'sled_volume'])
            ->delete();

        // 5. ASSERT zero remaining sled_* records (raw count includes soft-deleted) before dropping.
        $remainingSledPrCount = DB::table('personal_records')
            ->whereIn('pr_type', ['sled_weight', 'sled_distance', 'sled_volume'])
            ->count();

        if ($remainingSledPrCount > 0) {
            throw new \RuntimeException("Aborting Migration B: Found {$remainingSledPrCount} personal_records still referencing sled_* pr_type.");
        }

        // 4. Drop sled_* from the enum
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('personal_records', function (Blueprint $table) {
                $table->dropIndex('personal_records_user_id_exercise_id_pr_type_index');
            });

            Schema::table('personal_records', function (Blueprint $table) {
                $table->dropColumn('pr_type');
            });

            Schema::table('personal_records', function (Blueprint $table) {
                $table->enum('pr_type', [
                    'one_rm', 'volume', 'rep_specific', 'hypertrophy', 'time', 'endurance',
                    'density', 'consistency', 'load', 'distance', 'duration', 'speed'
                ])->nullable()->after('lift_log_id');
            });

            Schema::table('personal_records', function (Blueprint $table) {
                $table->index(['user_id', 'exercise_id', 'pr_type']);
            });
        } else {
            DB::statement("ALTER TABLE personal_records MODIFY COLUMN pr_type ENUM('one_rm','volume','rep_specific','hypertrophy','time','endurance','density','consistency','load','distance','duration','speed') NULL");
        }
    }

    public function down(): void
    {
        // Re-add sled_* to enum first
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('personal_records', function (Blueprint $table) {
                $table->dropIndex('personal_records_user_id_exercise_id_pr_type_index');
            });

            Schema::table('personal_records', function (Blueprint $table) {
                $table->dropColumn('pr_type');
            });

            Schema::table('personal_records', function (Blueprint $table) {
                $table->enum('pr_type', [
                    'one_rm', 'volume', 'rep_specific', 'hypertrophy', 'time', 'endurance',
                    'density', 'consistency', 'sled_weight', 'sled_distance', 'sled_volume',
                    'load', 'distance', 'duration', 'speed'
                ])->nullable()->after('lift_log_id');
            });

            Schema::table('personal_records', function (Blueprint $table) {
                $table->index(['user_id', 'exercise_id', 'pr_type']);
            });
        } else {
            DB::statement("ALTER TABLE personal_records MODIFY COLUMN pr_type ENUM('one_rm','volume','rep_specific','hypertrophy','time','endurance','density','consistency','sled_weight','sled_distance','sled_volume','load','distance','duration','speed') NULL");
        }

        // Restore exercises.exercise_type
        Exercise::where('log_type', 'sled')->update(['exercise_type' => 'sled']);
        Exercise::where('log_type', 'weighted-carry')->update(['exercise_type' => 'static_hold']);
    }
};
