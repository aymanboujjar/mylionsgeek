<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('attendances')) {
            Schema::create('attendances', function (Blueprint $table) {
			$table->id();
			$table->integer('formation_id');
			$table->string('attendance_day');
			$table->string('staff_name');
			$table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};