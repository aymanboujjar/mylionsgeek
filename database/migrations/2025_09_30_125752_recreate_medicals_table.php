<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('medicals')) {
            Schema::create('medicals', function (Blueprint $table) {
			$table->id();
			$table->integer('user_id');
			$table->string('mc_document');
			$table->string('description');
			$table->string('author');
			$table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('medicals');
    }
};