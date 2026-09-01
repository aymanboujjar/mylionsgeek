<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('studio_images')) {
            Schema::create('studio_images', function (Blueprint $table) {
			$table->id();
			$table->string('image');
			$table->integer('studio_id');
			$table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_images');
    }
};