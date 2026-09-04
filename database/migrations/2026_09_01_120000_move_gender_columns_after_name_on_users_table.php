<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Physical column order only. SQLite ignores ->after(), so existing SQLite
 * databases are rebuilt; MySQL/MariaDB use MODIFY ... AFTER.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'gender')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $this->reorderForMysql();

            return;
        }

        if ($driver === 'sqlite') {
            $this->reorderForSqlite();
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'gender')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE users MODIFY gender ENUM('male', 'female') NULL AFTER cin");
            if (Schema::hasColumn('users', 'has_handicap')) {
                DB::statement('ALTER TABLE users MODIFY has_handicap TINYINT(1) NULL AFTER gender');
            }

            return;
        }

        if ($driver === 'sqlite') {
            $this->reorderForSqlite(afterColumn: 'cin');
        }
    }

    private function reorderForMysql(): void
    {
        DB::statement("ALTER TABLE users MODIFY gender ENUM('male', 'female') NULL AFTER name");

        if (Schema::hasColumn('users', 'has_handicap')) {
            DB::statement('ALTER TABLE users MODIFY has_handicap TINYINT(1) NULL AFTER gender');
        }
    }

    private function reorderForSqlite(string $afterColumn = 'name'): void
    {
        $columns = collect(DB::select('PRAGMA table_info(users)'));
        $currentOrder = $columns->pluck('name')->all();

        if ($this->genderAlreadyAfter($currentOrder, $afterColumn)) {
            return;
        }

        $newOrder = $this->buildColumnOrder($currentOrder, $afterColumn);
        $createSql = (string) DB::scalar("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'users'");

        if ($createSql === '') {
            return;
        }

        $columnSql = $this->buildSqliteColumnDefinitions($columns, $createSql, $newOrder);
        $foreignKeySql = $this->extractSqliteForeignKeys($createSql);

        DB::statement('PRAGMA foreign_keys=OFF');

        DB::statement('DROP TABLE IF EXISTS users__gender_reordered');
        DB::statement('CREATE TABLE users__gender_reordered ('.$columnSql.$foreignKeySql.')');

        $quoted = implode(', ', array_map(fn (string $column) => '"'.$column.'"', $newOrder));
        DB::statement("INSERT INTO users__gender_reordered ({$quoted}) SELECT {$quoted} FROM users");

        DB::statement('DROP TABLE users');
        DB::statement('ALTER TABLE users__gender_reordered RENAME TO users');

        DB::statement('PRAGMA foreign_keys=ON');
    }

    /**
     * @param  list<string>  $currentOrder
     * @return list<string>
     */
    private function buildColumnOrder(array $currentOrder, string $afterColumn): array
    {
        $without = array_values(array_filter(
            $currentOrder,
            fn (string $name) => ! in_array($name, ['gender', 'has_handicap'], true),
        ));

        $anchorIndex = array_search($afterColumn, $without, true);

        if ($anchorIndex === false) {
            return $currentOrder;
        }

        $insert = ['gender'];
        if (in_array('has_handicap', $currentOrder, true)) {
            $insert[] = 'has_handicap';
        }

        array_splice($without, $anchorIndex + 1, 0, $insert);

        return $without;
    }

    /**
     * @param  list<string>  $currentOrder
     */
    private function genderAlreadyAfter(array $currentOrder, string $afterColumn): bool
    {
        $anchorIndex = array_search($afterColumn, $currentOrder, true);
        $genderIndex = array_search('gender', $currentOrder, true);

        return $anchorIndex !== false
            && $genderIndex !== false
            && $genderIndex === $anchorIndex + 1;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $columns
     * @param  list<string>  $order
     */
    private function buildSqliteColumnDefinitions($columns, string $createSql, array $order): string
    {
        $byName = $columns->keyBy('name');
        $definitions = [];

        foreach ($order as $name) {
            $column = $byName->get($name);

            if ($column === null) {
                continue;
            }

            $definition = '"'.$column->name.'" '.$column->type;

            if ((int) $column->pk === 1) {
                $definition .= ' primary key autoincrement';
            } elseif ((int) $column->notnull === 1) {
                $definition .= ' not null';
            }

            if ($column->dflt_value !== null) {
                $definition .= ' default '.$column->dflt_value;
            }

            if ($name === 'gender' && preg_match('/"gender"\s+varchar\s+check\s+\("gender"\s+in\s+\([^)]+\)\)/i', $createSql, $match)) {
                $definition = $match[0];
            }

            if ($name === 'program_status' && preg_match('/"program_status"\s+varchar\s+check\s+\("program_status"\s+in\s+\([^)]+\)\)/i', $createSql, $match)) {
                $definition = $match[0];
            }

            $definitions[] = $definition;
        }

        return implode(', ', $definitions);
    }

    private function extractSqliteForeignKeys(string $createSql): string
    {
        if (! preg_match('/,\s*(foreign key\(.+\))\s*\)\s*$/is', $createSql, $match)) {
            return '';
        }

        return ', '.$match[1];
    }
};
