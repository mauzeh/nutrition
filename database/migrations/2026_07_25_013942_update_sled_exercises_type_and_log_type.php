<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix exercise_type and log_type for sled exercises.
 *
 * Before this migration:
 *   - Sled Push (id=268): exercise_type='cardio', log_type='cardio'
 *   - Sled Pull (id=212): exercise_type='regular', log_type='barbell'
 *
 * These were created before Logger had sled support. Now that SledExerciseType
 * exists, we correct them so PR detection and display use the right strategy.
 *
 * Rollback restores original values.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Update any exercise that has log_type='sled' (set by sync) but wrong exercise_type
        DB::table('exercises')
            ->where('log_type', 'sled')
            ->where('exercise_type', '!=', 'sled')
            ->update(['exercise_type' => 'sled']);

        // Update known sled exercises that still have the wrong log_type
        DB::table('exercises')
            ->where('canonical_name', 'sled_push')
            ->update(['exercise_type' => 'sled', 'log_type' => 'sled']);

        DB::table('exercises')
            ->where('canonical_name', 'sled_pull')
            ->update(['exercise_type' => 'sled', 'log_type' => 'sled']);

        // Also update any lift_logs that reference these exercises but have stale/missing log_type
        DB::table('lift_logs')
            ->whereIn('exercise_id', function ($query) {
                $query->select('id')
                    ->from('exercises')
                    ->where('exercise_type', 'sled');
            })
            ->where(function ($query) {
                $query->where('log_type', '!=', 'sled')
                    ->orWhereNull('log_type');
            })
            ->update(['log_type' => 'sled']);
    }

    public function down(): void
    {
        // Restore Sled Push to its previous state
        DB::table('exercises')
            ->where('canonical_name', 'sled_push')
            ->update(['exercise_type' => 'cardio', 'log_type' => 'cardio']);

        // Restore Sled Pull to its previous state
        DB::table('exercises')
            ->where('canonical_name', 'sled_pull')
            ->update(['exercise_type' => 'regular', 'log_type' => 'barbell']);

        // Restore lift_logs for sled_push back to cardio
        DB::table('lift_logs')
            ->whereIn('exercise_id', function ($query) {
                $query->select('id')
                    ->from('exercises')
                    ->where('canonical_name', 'sled_push');
            })
            ->where('log_type', 'sled')
            ->update(['log_type' => 'cardio']);

        // Restore lift_logs for sled_pull back to barbell
        DB::table('lift_logs')
            ->whereIn('exercise_id', function ($query) {
                $query->select('id')
                    ->from('exercises')
                    ->where('canonical_name', 'sled_pull');
            })
            ->where('log_type', 'sled')
            ->update(['log_type' => 'barbell']);
    }
};
