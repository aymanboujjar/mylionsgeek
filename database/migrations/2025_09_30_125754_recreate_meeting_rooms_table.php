<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('meeting_rooms')) {
            Schema::create('meeting_rooms', function (Blueprint $table) {
			$table->id();
			$table->string('name');
			$table->integer('state');
			$table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_rooms');
    }
};