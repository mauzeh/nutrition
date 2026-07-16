<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    /**
     * Soft-delete the "Squirbies" joke exercise and its associated lift logs.
     * This is a user-scoped exercise created by Alejandro (user_id=10) that
     * is not a real exercise and should not appear in reconciliation or restore.
     */
    public function up(): void
    {
        $now = Carbon::now();

        // Find the exercise
        $exercise = DB::table('exercises')
            ->where('canonical_name', 'squirbies')
            ->whereNull('deleted_at')
            ->first();

        if (!$exercise) {
            return;
        }

        // Soft-delete associated lift logs
        DB::table('lift_logs')
            ->where('exercise_id', $exercise->id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now]);

        // Soft-delete the exercise itself
        DB::table('exercises')
            ->where('id', $exercise->id)
            ->update(['deleted_at' => $now]);
    }

    /**
     * Reverse: restore the exercise and its logs.
     */
    public function down(): void
    {
        // Restore the exercise
        $exercise = DB::table('exercises')
            ->where('canonical_name', 'squirbies')
            ->whereNotNull('deleted_at')
            ->first();

        if (!$exercise) {
            return;
        }

        DB::table('exercises')
            ->where('id', $exercise->id)
            ->update(['deleted_at' => null]);

        // Restore associated lift logs
        DB::table('lift_logs')
            ->where('exercise_id', $exercise->id)
            ->whereNotNull('deleted_at')
            ->update(['deleted_at' => null]);
    }
};
