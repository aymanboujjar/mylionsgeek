<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ModifyStatusEnumInUsersTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->enum('status', ['Working', 'Studying', 'Internship', 'Unemployed', 'Freelancing'])->change();
            });
        }
    }

    public function down()
    {
        // Revert to previous values if needed
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->enum('status', ['Working', 'Studying', 'Internship', 'Unemployed', 'Freelancing', 'Quit'])->change(); // Replace with old values
            });
        }
    }
}
