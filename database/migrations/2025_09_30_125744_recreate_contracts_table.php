<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('contracts')) {
            Schema::create('contracts', function (Blueprint $table) {
			$table->id();
			$table->integer('user_id');
			$table->integer('reservation_id')->nullable()->default('NULL');
			$table->string('contract');
			$table->string('type');
			$table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};