<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('receive_notifications')) {
            Schema::create('receive_notifications', function (Blueprint $table) {
			$table->id();
			$table->integer('student');
			$table->integer('cowork');
			$table->integer('moderateur');
			$table->integer('coach');
			$table->integer('user_id');
			$table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('receive_notifications');
    }
};
