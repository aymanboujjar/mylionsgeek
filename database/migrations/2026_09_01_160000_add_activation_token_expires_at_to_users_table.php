<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'activation_token_expires_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('activation_token_expires_at')->nullable()->after('activation_token');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'activation_token_expires_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('activation_token_expires_at');
            });
        }
    }
};
