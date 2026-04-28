<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Композитный индекс (sheet_id, row_index) сильно ускоряет выборку строк
 * активного листа: WHERE sheet_id = ? ORDER BY row_index. Без индекса при
 * 10K+ строк это full scan + sort. С индексом — index range scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheet_data', function (Blueprint $table) {
            $table->index(['sheet_id', 'row_index'], 'sheet_data_sheet_id_row_index_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sheet_data', function (Blueprint $table) {
            $table->dropIndex('sheet_data_sheet_id_row_index_idx');
        });
    }
};
