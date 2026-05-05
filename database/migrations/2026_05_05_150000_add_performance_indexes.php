<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Индексы под реальные горячие запросы. Все CREATE INDEX IF NOT EXISTS —
 * миграция идемпотентна и безопасна для повторного запуска.
 *
 * Ничего НЕ ломает: индексы только ускоряют чтение, на запись добавляют
 * минимальный оверхед (десятки мкс на INSERT/UPDATE), которым в нашем профиле
 * нагрузки можно пренебречь.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // sheet_audit_logs — самая медленная страница (Журнал). Сортировка по
        // created_at DESC + фильтр по sheet_id/user_id. Без составного индекса
        // PG делает Seq Scan + Sort при больших объёмах.
        $this->createIndexIfNotExists(
            'sheet_audit_logs',
            'idx_audit_logs_sheet_created',
            ['sheet_id', 'created_at']
        );
        $this->createIndexIfNotExists(
            'sheet_audit_logs',
            'idx_audit_logs_user_created',
            ['user_id', 'created_at']
        );
        $this->createIndexIfNotExists(
            'sheet_audit_logs',
            'idx_audit_logs_created',
            ['created_at']
        );

        // sheets — список листов сортируется по order, обычно фильтр по user_id
        // (для не-админов). Этот индекс закрывает оба варианта запроса.
        $this->createIndexIfNotExists(
            'sheets',
            'idx_sheets_user_order',
            ['user_id', 'order']
        );

        // sheet_data уже имеет UNIQUE(sheet_id, row_index), который служит
        // и индексом для сортировки. Дополнительно ничего не нужно.

        // PG: ANALYZE для обновления статистики, чтобы планировщик
        // сразу начал использовать новые индексы.
        if ($driver === 'pgsql') {
            DB::statement('ANALYZE sheet_audit_logs');
            DB::statement('ANALYZE sheets');
        }
    }

    public function down(): void
    {
        $this->dropIndexIfExists('sheet_audit_logs', 'idx_audit_logs_sheet_created');
        $this->dropIndexIfExists('sheet_audit_logs', 'idx_audit_logs_user_created');
        $this->dropIndexIfExists('sheet_audit_logs', 'idx_audit_logs_created');
        $this->dropIndexIfExists('sheets', 'idx_sheets_user_order');
    }

    private function createIndexIfNotExists(string $table, string $name, array $columns): void
    {
        if (!Schema::hasTable($table)) return;
        $driver = DB::connection()->getDriverName();
        $cols = collect($columns)->map(fn ($c) => "\"{$c}\"")->implode(', ');

        if ($driver === 'pgsql') {
            DB::statement("CREATE INDEX IF NOT EXISTS \"{$name}\" ON \"{$table}\" ({$cols})");
        } elseif ($driver === 'mysql') {
            // MySQL не поддерживает IF NOT EXISTS для CREATE INDEX до 8.0.29 —
            // проверяем вручную через information_schema.
            $exists = DB::table('information_schema.statistics')
                ->where('table_schema', DB::connection()->getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $name)
                ->exists();
            if (!$exists) {
                $colsBacktick = collect($columns)->map(fn ($c) => "`{$c}`")->implode(', ');
                DB::statement("CREATE INDEX `{$name}` ON `{$table}` ({$colsBacktick})");
            }
        } elseif ($driver === 'sqlite') {
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$table} (" . collect($columns)->implode(', ') . ")");
        }
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (!Schema::hasTable($table)) return;
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement("DROP INDEX IF EXISTS \"{$name}\"");
        } elseif ($driver === 'mysql') {
            $exists = DB::table('information_schema.statistics')
                ->where('table_schema', DB::connection()->getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $name)
                ->exists();
            if ($exists) {
                DB::statement("DROP INDEX `{$name}` ON `{$table}`");
            }
        }
    }
};
