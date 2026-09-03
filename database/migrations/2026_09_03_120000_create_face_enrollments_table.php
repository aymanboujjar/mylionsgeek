<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('face_enrollments')) {
            return;
        }

        Schema::create('face_enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('disk');
            $table->string('path');
            $table->unsignedBigInteger('enrolled_by')->nullable();
            $table->timestamp('enrolled_at');
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_enrollments');
    }
};
