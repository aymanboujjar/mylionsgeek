<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_codes')) {
            Schema::create('user_codes', function (Blueprint $table) {
			$table->id();
			$table->integer('user_id');
			$table->integer('code');
			$table->string('expiration');
			$table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_codes');
    }
};