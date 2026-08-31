<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('activity_log')) {
            Schema::create('activity_log', function (Blueprint $table) {
			$table->id();
			$table->string('log_name')->nullable()->default('NULL');
			$table->string('description');
			$table->string('subject_type')->nullable()->default('NULL');
			$table->string('event')->nullable()->default('NULL');
			$table->integer('subject_id')->nullable()->default('NULL');
			$table->string('causer_type')->nullable()->default('NULL');
			$table->integer('causer_id')->nullable()->default('NULL');
			$table->string('properties')->nullable()->default('NULL');
			$table->string('batch_uuid')->nullable()->default('NULL');
			$table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};