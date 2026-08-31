<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
			$table->id();
			$table->string('name');
			$table->string('email');
			$table->string('email_verified_at')->nullable()->default('NULL');
			$table->string('password');
			$table->string('phone')->nullable()->default('NULL');
			$table->string('cin')->nullable();
			$table->string('status');
			$table->string('entreprise')->nullable()->default('NULL');
			$table->string('image')->default('pdp.png');
			$table->integer('access_studio')->default('0');
			$table->integer('access_cowork')->default('0');
			$table->integer('double_auth')->default('1');
			$table->integer('auth_activation')->default('1');
			$table->integer('password_reset')->default('0');
			$table->integer('formation_id')->nullable()->default('NULL');
			$table->integer('account_state')->default('0');
			$table->integer('darkmode')->default('0');
			$table->string('remember_token')->nullable()->default('NULL');
			$table->string('role')->default('student');
			$table->string('wakatime_api_key')->nullable();
			$table->integer('previous_week_rank')->nullable();
			$table->timestamp('last_rank_update')->nullable();
			$table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};