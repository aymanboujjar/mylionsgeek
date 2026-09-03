<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renames the program_status value 'alumni' to 'completed'.
 *
 * 'completed' now means "finished the training without earning a certificate",
 * as opposed to 'laureate' which means "finished and certified".
 *
 * SQLite enforces enums with a CHECK constraint that cannot be altered in place,
 * so the column is rebuilt through a temporary column. This mirrors the approach
 * already used by the gender enum migration in this project.
 */
return new class extends Migration
{
    private const OLD_VALUES = ['active', 'laureate', 'alumni', 'left'];

    private const NEW_VALUES = ['active', 'laureate', 'completed', 'left'];

    public function up(): void
    {
        $this->rewriteEnum(self::NEW_VALUES, from: 'alumni', to: 'completed');
    }

    public function down(): void
    {
        $this->rewriteEnum(self::OLD_VALUES, from: 'completed', to: 'alumni');
    }

    /**
     * Repoints the program_status enum at $targetValues, remapping $from to $to.
     *
     * @param  list<string>  $targetValues
     */
    private function rewriteEnum(array $targetValues, string $from, string $to): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->rewriteEnumForSqlite($targetValues, $from, $to);

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $this->rewriteEnumForMysql($targetValues, $from, $to);

            return;
        }

        if ($driver === 'pgsql') {
            $this->rewriteEnumForPgsql($targetValues, $from, $to);

            return;
        }

        // Any other driver: remap the value and leave constraint handling to the DBMS.
        DB::table('users')->where('program_status', $from)->update(['program_status' => $to]);
    }

    /**
     * @param  list<string>  $targetValues
     */
    private function rewriteEnumForSqlite(array $targetValues, string $from, string $to): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('program_status_tmp')->nullable();
        });

        // Park every value in an unconstrained column, remapping the renamed one.
        DB::table('users')->update(['program_status_tmp' => DB::raw('program_status')]);
        DB::table('users')->where('program_status_tmp', $from)->update(['program_status_tmp' => $to]);

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
     * Widen the enum to accept both spellings before remapping, so no row is ever
     * momentarily invalid, then narrow it to the target set.
     *
     * @param  list<string>  $targetValues
     */
    private function rewriteEnumForMysql(array $targetValues, string $from, string $to): void
    {
        $union = array_values(array_unique(array_merge(self::OLD_VALUES, self::NEW_VALUES)));

        DB::statement($this->modifyEnumStatement($union));
        DB::table('users')->where('program_status', $from)->update(['program_status' => $to]);
        DB::statement($this->modifyEnumStatement($targetValues));
    }

    /**
     * PostgreSQL stores Laravel enums as varchar + CHECK. Widen the check to accept
     * both spellings before remapping, then narrow it to the target set.
     *
     * @param  list<string>  $targetValues
     */
    private function rewriteEnumForPgsql(array $targetValues, string $from, string $to): void
    {
        $union = array_values(array_unique(array_merge(self::OLD_VALUES, self::NEW_VALUES)));

        $this->replaceProgramStatusCheckConstraint($union);
        DB::table('users')->where('program_status', $from)->update(['program_status' => $to]);
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
