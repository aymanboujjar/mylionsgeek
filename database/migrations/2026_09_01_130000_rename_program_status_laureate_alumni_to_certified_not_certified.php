<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renames program_status values for clearer certificate wording:
 *   laureate -> certified
 *   alumni   -> not_certified
 */
return new class extends Migration
{
    private const OLD_VALUES = ['active', 'laureate', 'alumni', 'left'];

    private const NEW_VALUES = ['active', 'certified', 'not_certified', 'left'];

    public function up(): void
    {
        $this->rewriteEnum(self::NEW_VALUES, [
            'laureate' => 'certified',
            'alumni' => 'not_certified',
        ]);
    }

    public function down(): void
    {
        $this->rewriteEnum(self::OLD_VALUES, [
            'certified' => 'laureate',
            'not_certified' => 'alumni',
        ]);
    }

    /**
     * @param  list<string>  $targetValues
     * @param  array<string, string>  $remap
     */
    private function rewriteEnum(array $targetValues, array $remap): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->rewriteEnumForSqlite($targetValues, $remap);

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $this->rewriteEnumForMysql($targetValues, $remap);

            return;
        }

        if ($driver === 'pgsql') {
            $this->rewriteEnumForPgsql($targetValues, $remap);

            return;
        }

        foreach ($remap as $from => $to) {
            DB::table('users')->where('program_status', $from)->update(['program_status' => $to]);
        }
    }

    /**
     * @param  list<string>  $targetValues
     * @param  array<string, string>  $remap
     */
    private function rewriteEnumForSqlite(array $targetValues, array $remap): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('program_status_tmp')->nullable();
        });

        DB::table('users')->orderBy('id')->each(function ($user) use ($remap) {
            $current = $user->program_status;
            $mapped = $remap[$current] ?? $current;

            DB::table('users')->where('id', $user->id)->update(['program_status_tmp' => $mapped]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('program_status');
        });

        Schema::table('users', function (Blueprint $table) use ($targetValues) {
            $table->enum('program_status', $targetValues)->nullable();
        });

        DB::table('users')->update(['program_status' => DB::raw('program_status_tmp')]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('program_status_tmp');
        });
    }

    /**
     * @param  list<string>  $targetValues
     * @param  array<string, string>  $remap
     */
    private function rewriteEnumForMysql(array $targetValues, array $remap): void
    {
        $union = array_values(array_unique(array_merge(self::OLD_VALUES, self::NEW_VALUES)));

        DB::statement($this->modifyEnumStatement($union));

        foreach ($remap as $from => $to) {
            DB::table('users')->where('program_status', $from)->update(['program_status' => $to]);
        }

        DB::statement($this->modifyEnumStatement($targetValues));
    }

    /**
     * @param  list<string>  $targetValues
     * @param  array<string, string>  $remap
     */
    private function rewriteEnumForPgsql(array $targetValues, array $remap): void
    {
        $union = array_values(array_unique(array_merge(self::OLD_VALUES, self::NEW_VALUES)));

        $this->replaceProgramStatusCheckConstraint($union);

        foreach ($remap as $from => $to) {
            DB::table('users')->where('program_status', $from)->update(['program_status' => $to]);
        }

        $this->replaceProgramStatusCheckConstraint($targetValues);
    }

    private function dropProgramStatusCheckConstraint(): void
    {
        $constraints = DB::select("
            SELECT c.conname
            FROM pg_constraint c
            JOIN pg_class t ON c.conrelid = t.oid
            JOIN pg_namespace n ON t.relnamespace = n.oid
            WHERE t.relname = 'users'
              AND n.nspname = current_schema()
              AND c.contype = 'c'
              AND pg_get_constraintdef(c.oid) LIKE '%program_status%'
        ");

        foreach ($constraints as $constraint) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT "'.$constraint->conname.'"');
        }
    }

    /**
     * @param  list<string>  $values
     */
    private function replaceProgramStatusCheckConstraint(array $values): void
    {
        $this->dropProgramStatusCheckConstraint();

        $quoted = implode(', ', array_map(fn (string $value) => "'".$value."'", $values));

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_program_status_check '
            ."CHECK (program_status IS NULL OR program_status IN ({$quoted}))"
        );
    }

    /**
     * @param  list<string>  $values
     */
    private function modifyEnumStatement(array $values): string
    {
        $quoted = implode(', ', array_map(fn (string $value) => "'".$value."'", $values));

        return "ALTER TABLE users MODIFY program_status ENUM({$quoted}) NULL";
    }
};
