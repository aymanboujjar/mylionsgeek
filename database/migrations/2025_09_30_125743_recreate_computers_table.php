<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('computers')) {
            Schema::create('computers', function (Blueprint $table) {
			$table->id();
			$table->uuid('uuid')->nullable()->unique();
			$table->string('reference');
			$table->string('cpu');
			$table->string('gpu');
			$table->integer('state');
			$table->integer('user_id')->default('0');
			$table->string('start')->nullable()->default('NULL');
			$table->string('end')->nullable()->default('NULL');
			$table->string('mark');
			$table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('computers');
    }
};