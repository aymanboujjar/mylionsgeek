<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('reservation_teams')) {
            Schema::create('reservation_teams', function (Blueprint $table) {
			$table->id();
			$table->integer('user_id');
			$table->integer('reservation_id');
			$table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_teams');
    }
};