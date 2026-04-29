<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UNIQUE(sheet_id, row_index) на sheet_data.
 *
 * Зачем:
 *   1. Защита от race condition при concurrent insert/delete row.
 *   2. Делает корректным upsert(['sheet_id','row_index'], ['row_data']) —
 *      без UNIQUE Postgres не знает, по какому ключу разрешать конфликт.
 *
 * Перед добавлением — дедуплицируем (на всякий случай). Стратегия:
 *   из каждой группы дублей оставляем строку с МАКСИМАЛЬНЫМ id (т.е. самую свежую,
 *   потому что autoincrement растёт со временем и последняя запись «победила»).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Дедуп: оставляем самую свежую запись (max id) в каждой группе (sheet_id, row_index).
        DB::statement("
            DELETE FROM sheet_data sd1
            USING sheet_data sd2
            WHERE sd1.sheet_id = sd2.sheet_id
              AND sd1.row_index = sd2.row_index
              AND sd1.id < sd2.id
        ");

        // 2. Добавляем UNIQUE. Используем именованный constraint,
        // чтобы upsert мог на него явно ссылаться при конфликтах.
        Schema::table('sheet_data', function ($table) {
            $table->unique(['sheet_id', 'row_index'], 'sheet_data_sheet_row_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sheet_data', function ($table) {
            $table->dropUnique('sheet_data_sheet_row_unique');
        });
    }
};
