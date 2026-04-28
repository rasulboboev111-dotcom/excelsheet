<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал действий: кто, когда, на каком листе и что сделал.
 * Видимость: только админ (отдельная страница).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheet_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sheet_id')->nullable()->constrained()->nullOnDelete();
            // Тип события: cell_edit, sheet_created, sheet_renamed, sheet_deleted,
            // sheet_imported, row_inserted, row_deleted, etc.
            $table->string('action', 64);
            // Подробности события: {rows_changed, sample, old_name, new_name, ...}
            $table->json('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['sheet_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_audit_logs');
    }
};
