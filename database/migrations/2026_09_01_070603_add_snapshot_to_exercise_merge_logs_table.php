<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('exercise_merge_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('admin_user_id')->nullable()->change();
            $table->string('admin_email')->nullable()->change();
            $table->json('snapshot')->nullable()->after('alias_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exercise_merge_logs', function (Blueprint $table) {
            $table->dropColumn('snapshot');
            $table->unsignedBigInteger('admin_user_id')->nullable(false)->change();
            $table->string('admin_email')->nullable(false)->change();
        });
    }
};
