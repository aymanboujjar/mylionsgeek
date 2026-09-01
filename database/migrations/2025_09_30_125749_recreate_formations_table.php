<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('formations')) {
            Schema::create('formations', function (Blueprint $table) {
			$table->id();
			$table->string('name');
			$table->string('img')->default('default_training.jpg');
			$table->string('start_time')->nullable()->default('NULL');
			$table->string('end_time')->nullable()->default('NULL');
			$table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('formations');
    }
};