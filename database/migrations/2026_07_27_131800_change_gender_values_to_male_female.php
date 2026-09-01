<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->string('gender_tmp')->nullable();
            });

            DB::table('users')->orderBy('id')->each(function ($user) {
                $mapped = match ($user->gender) {
                    'homme', 'male' => 'male',
                    'femme', 'female' => 'female',
                    default => null,
                };
                DB::table('users')->where('id', $user->id)->update(['gender_tmp' => $mapped]);
            });

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('gender');
            });

            Schema::table('users', function (Blueprint $table) {
                $table->enum('gender', ['male', 'female'])->nullable();
            });

            DB::table('users')->orderBy('id')->each(function ($user) {
                DB::table('users')->where('id', $user->id)->update(['gender' => $user->gender_tmp]);
            });

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('gender_tmp');
            });

            return;
        }

        DB::table('users')->where('gender', 'homme')->update(['gender' => 'male']);
        DB::table('users')->where('gender', 'femme')->update(['gender' => 'female']);

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY gender ENUM('male', 'female') NULL");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->string('gender_tmp')->nullable();
            });

            DB::table('users')->orderBy('id')->each(function ($user) {
                $mapped = match ($user->gender) {
                    'male', 'homme' => 'homme',
                    'female', 'femme' => 'femme',
                    default => null,
                };
                DB::table('users')->where('id', $user->id)->update(['gender_tmp' => $mapped]);
            });

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('gender');
            });

            Schema::table('users', function (Blueprint $table) {
                $table->enum('gender', ['homme', 'femme'])->nullable();
            });

            DB::table('users')->orderBy('id')->each(function ($user) {
                DB::table('users')->where('id', $user->id)->update(['gender' => $user->gender_tmp]);
            });

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('gender_tmp');
            });

            return;
        }

        if ($driver === 'mysql') {
            // Accept both spellings before remapping, so no row is momentarily invalid.
            DB::statement("ALTER TABLE users MODIFY gender ENUM('homme', 'femme', 'male', 'female') NULL");
        }

        DB::table('users')->where('gender', 'male')->update(['gender' => 'homme']);
        DB::table('users')->where('gender', 'female')->update(['gender' => 'femme']);

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY gender ENUM('homme', 'femme') NULL");
        }
    }
};
