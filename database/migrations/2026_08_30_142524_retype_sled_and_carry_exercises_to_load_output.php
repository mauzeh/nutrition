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

        // 2. Clean up legacy sled_* PR records & recompute PRs for all users of affected exercises
        DB::table('personal_records')
            ->whereIn('pr_type', ['sled_weight', 'sled_distance', 'sled_volume'])
            ->delete();

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

        // 3. ASSERT zero remaining sled_* records before dropping
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
