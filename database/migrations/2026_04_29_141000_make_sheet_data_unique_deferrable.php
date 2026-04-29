<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Делаем UNIQUE(sheet_id, row_index) DEFERRABLE INITIALLY IMMEDIATE.
 *
 * Зачем:
 *   При insertRow/deleteRow мы сдвигаем диапазон строк ОДНИМ UPDATE'ом
 *   (row_index = row_index + 1). PostgreSQL проверяет UNIQUE построчно и не
 *   гарантирует порядок обработки — поэтому при сдвиге [1,2,3] → [2,3,4]
 *   на промежуточном шаге может оказаться две строки с row_index=2 → ошибка.
 *
 *   DEFERRABLE INITIALLY IMMEDIATE = constraint работает как обычно
 *   (проверка после каждой команды), но в транзакции можно вызвать
 *   SET CONSTRAINTS sheet_data_sheet_row_unique DEFERRED — тогда проверка
 *   откладывается до COMMIT, и промежуточное состояние UPDATE-команды
 *   не валится.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sheet_data DROP CONSTRAINT sheet_data_sheet_row_unique');
        DB::statement('ALTER TABLE sheet_data ADD CONSTRAINT sheet_data_sheet_row_unique
            UNIQUE (sheet_id, row_index) DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sheet_data DROP CONSTRAINT sheet_data_sheet_row_unique');
        DB::statement('ALTER TABLE sheet_data ADD CONSTRAINT sheet_data_sheet_row_unique
            UNIQUE (sheet_id, row_index)');
    }
};
