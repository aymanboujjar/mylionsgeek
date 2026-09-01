<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('meeting_room_images')) {
            Schema::create('meeting_room_images', function (Blueprint $table) {
			$table->id();
			$table->string('image');
			$table->integer('meeting_room_id');
			$table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_room_images');
    }
};