<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add sled PR types (sled_weight, sled_distance, sled_volume) to the
 * personal_records.pr_type enum column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('personal_records', function (Blueprint $table) {
                $table->dropIndex('personal_records_user_id_exercise_id_pr_type_index');
            });

            Schema::table('personal_records', function (Blueprint $table) {
                $table->dropColumn('pr_type');
            });

            Schema::table('personal_records', function (Blueprint $table) {
                $table->enum('pr_type', ['one_rm', 'volume', 'rep_specific', 'hypertrophy', 'time', 'endurance', 'density', 'consistency', 'sled_weight', 'sled_distance', 'sled_volume'])->nullable()->after('lift_log_id');
            });

            Schema::table('personal_records', function (Blueprint $table) {
                $table->index(['user_id', 'exercise_id', 'pr_type']);
            });
        } else {
            DB::statement("ALTER TABLE personal_records MODIFY COLUMN pr_type ENUM('one_rm','volume','rep_specific','hypertrophy','time','endurance','density','consistency','sled_weight','sled_distance','sled_volume') NULL");
        }
    }

    public function down(): void
    {
        DB::table('personal_records')
            ->whereIn('pr_type', ['sled_weight', 'sled_distance', 'sled_volume'])
            ->delete();

        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('personal_records', function (Blueprint $table) {
                $table->dropIndex('personal_records_user_id_exercise_id_pr_type_index');
            });

            Schema::table('personal_records', function (Blueprint $table) {
                $table->dropColumn('pr_type');
            });

            Schema::table('personal_records', function (Blueprint $table) {
                $table->enum('pr_type', ['one_rm', 'volume', 'rep_specific', 'hypertrophy', 'time', 'endurance', 'density', 'consistency'])->nullable()->after('lift_log_id');
            });

            Schema::table('personal_records', function (Blueprint $table) {
                $table->index(['user_id', 'exercise_id', 'pr_type']);
            });
        } else {
            DB::statement("ALTER TABLE personal_records MODIFY COLUMN pr_type ENUM('one_rm','volume','rep_specific','hypertrophy','time','endurance','density','consistency') NULL");
        }
    }
};
