<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('reservation_equipment')) {
            Schema::create('reservation_equipment', function (Blueprint $table) {
			$table->id();
			$table->integer('reservation_id');
			$table->integer('equipment_id');
			$table->string('day');
			$table->string('start');
			$table->string('end');
			$table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_equipment');
    }
};