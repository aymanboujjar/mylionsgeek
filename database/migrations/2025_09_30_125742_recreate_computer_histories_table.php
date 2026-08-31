<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('computer_histories')) {
            Schema::create('computer_histories', function (Blueprint $table) {
			$table->id();
			$table->integer('user_id');
			$table->integer('computer_id');
			$table->string('start');
			$table->string('end');
			$table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('computer_histories');
    }
};