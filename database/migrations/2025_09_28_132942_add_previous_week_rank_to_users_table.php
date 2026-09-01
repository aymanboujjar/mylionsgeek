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
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'previous_week_rank')) {
            Schema::table('users', function (Blueprint $table) {
                $table->integer('previous_week_rank')->nullable()->after('wakatime_api_key');
                $table->timestamp('last_rank_update')->nullable()->after('previous_week_rank');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'previous_week_rank')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn(['previous_week_rank', 'last_rank_update']);
            });
        }
    }
};
