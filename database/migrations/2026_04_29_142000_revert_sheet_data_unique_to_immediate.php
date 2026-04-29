<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Возвращаем UNIQUE на IMMEDIATE-проверку.
 *
 * Зачем: PostgreSQL ON CONFLICT (используется в upsert) НЕ поддерживает
 * DEFERRABLE constraints как arbiter index. Так что DEFERRABLE для шифтов
 * insert/deleteRow несовместим с upsert в updateData.
 *
 * Чтобы поддержать оба сценария: UNIQUE остаётся IMMEDIATE,
 * а в insert/deleteRow используем трюк со смещением — временно отправляем
 * сдвигаемые строки в range >> MAX_ROW_INDEX, потом возвращаем со сдвигом.
 * Так промежуточное состояние не конфликтует.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sheet_data DROP CONSTRAINT sheet_data_sheet_row_unique');
        DB::statement('ALTER TABLE sheet_data ADD CONSTRAINT sheet_data_sheet_row_unique
            UNIQUE (sheet_id, row_index)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sheet_data DROP CONSTRAINT sheet_data_sheet_row_unique');
        DB::statement('ALTER TABLE sheet_data ADD CONSTRAINT sheet_data_sheet_row_unique
            UNIQUE (sheet_id, row_index) DEFERRABLE INITIALLY IMMEDIATE');
    }
};
