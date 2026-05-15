<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Приглашения на регистрацию. Админ создаёт ссылку с уникальным токеном
 * и раздаёт её людям, которым нужен доступ. Регистрация по ссылке
 * работает пока токен не отозван (revoked_at IS NULL). Срок жизни не
 * ограничен — отзыв только вручную через UI.
 *
 * Регистрация через эту ссылку всегда даёт роль обычного пользователя —
 * права админа/почты выдаются отдельно через /users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('uses_count')->default(0);
            $table->timestamps();

            // Быстрый поиск активных инвайтов для списка в UI.
            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
