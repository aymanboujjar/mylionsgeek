<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent restore for environments that already ran the group-chat migration
 * before the direct-pair unique was put back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('conversations')) {
            return;
        }

        $indexes = Schema::getIndexes('conversations');
        $hasPairUnique = collect($indexes)->contains(function (array $index) {
            $cols = $index['columns'] ?? [];

            return ($index['unique'] ?? false)
                && count($cols) === 2
                && in_array('user_one_id', $cols, true)
                && in_array('user_two_id', $cols, true);
        });

        if ($hasPairUnique) {
            return;
        }

        Schema::table('conversations', function (Blueprint $table) {
            $table->unique(['user_one_id', 'user_two_id']);
        });
    }

    public function down(): void
    {
        // Keep the unique — rolling this back would reintroduce duplicate DMs.
    }
};
