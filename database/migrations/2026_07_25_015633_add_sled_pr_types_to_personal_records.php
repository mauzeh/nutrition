<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add sled PR types (sled_weight, sled_distance, sled_volume) to the
 * personal_records.pr_type enum column.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE personal_records MODIFY COLUMN pr_type ENUM('one_rm','volume','rep_specific','hypertrophy','time','endurance','density','consistency','sled_weight','sled_distance','sled_volume') NULL");
    }

    public function down(): void
    {
        // Remove sled PR records first to avoid data truncation on rollback
        DB::table('personal_records')
            ->whereIn('pr_type', ['sled_weight', 'sled_distance', 'sled_volume'])
            ->delete();

        DB::statement("ALTER TABLE personal_records MODIFY COLUMN pr_type ENUM('one_rm','volume','rep_specific','hypertrophy','time','endurance','density','consistency') NULL");
    }
};
