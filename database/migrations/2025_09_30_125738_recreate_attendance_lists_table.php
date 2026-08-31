<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('attendance_lists')) {
            Schema::create('attendance_lists', function (Blueprint $table) {
			$table->id();
			$table->integer('user_id');
			$table->integer('attendance_id');
			$table->string('attendance_day');
			$table->string('morning')->default('Present');
			$table->string('lunch')->default('Present');
			$table->string('evening')->default('Present');
			$table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_lists');
    }
};