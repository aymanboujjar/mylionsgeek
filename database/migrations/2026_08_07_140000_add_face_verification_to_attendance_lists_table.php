<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_lists', function (Blueprint $table) {
            $table->string('face_verification_method')->nullable()->after('evening');
            $table->float('face_match_confidence')->nullable()->after('face_verification_method');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_lists', function (Blueprint $table) {
            $table->dropColumn(['face_verification_method', 'face_match_confidence']);
        });
    }
};
