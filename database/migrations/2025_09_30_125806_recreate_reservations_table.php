<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('reservations')) {
            Schema::create('reservations', function (Blueprint $table) {
			$table->id();
			$table->string('title');
			$table->string('description');
			$table->string('day');
			$table->string('start');
			$table->string('end');
			$table->integer('canceled')->default('0');
			$table->integer('passed')->default('0');
			$table->integer('approved')->default('0');
			$table->integer('user_id');
			$table->integer('studio_id')->nullable()->default('NULL');
			$table->integer('start_signed')->default('0');
			$table->integer('end_signed')->default('0');
			$table->string('type');
			$table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};