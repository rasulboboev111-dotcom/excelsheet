<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Хранение per-sheet UI-меты на сервере вместо localStorage.
 *
 * До этого `merges`, `colWidths`, `rowHeights`, `freezeRow/Col`, `validations`,
 * `hidden` лежали в localStorage админа, который их настроил. Другие юзеры
 * того же листа не видели объединений/закреплений/ширин — это был баг,
 * не фича. Теперь всё в `sheets.meta` (jsonb), shared между всеми пользователями.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sheets', function (Blueprint $table) {
            // jsonb с дефолтом {} — если меты ещё нет, фронт получит пустой объект
            // и применит свои дефолты (так же как раньше для пустого localStorage).
            $table->jsonb('meta')->default('{}')->after('columns');
        });
    }

    public function down(): void
    {
        Schema::table('sheets', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
