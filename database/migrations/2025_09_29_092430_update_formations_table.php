<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('formations') && !Schema::hasColumn('formations', 'category')) {
            Schema::table('formations', function (Blueprint $table) {
                $table->string('category')->nullable();
                $table->string('promo')->nullable(); // optional
                $table->string('user_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('formations', function (Blueprint $table) {
            if (Schema::hasColumn('formations', 'name')) {
                $table->dropColumn('name');
            }
            if (Schema::hasColumn('formations', 'img')) {
                $table->dropColumn('img');
            }
            if (Schema::hasColumn('formations', 'category')) {
                $table->dropColumn('category');
            }
            if (Schema::hasColumn('formations', 'promo')) {
                $table->dropColumn('promo');
            }
            if (Schema::hasColumn('formations', 'user_id')) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            }
        });
    }
};
