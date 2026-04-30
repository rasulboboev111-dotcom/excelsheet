<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Поля для Google OAuth — позволяют юзеру подключить свой Gmail и слать письма
 * с сайта от своего имени (не от noreply сайта).
 *
 * google_id              — уникальный id юзера в Google (sub claim)
 * google_email           — Gmail-адрес, который подключён (может отличаться от users.email)
 * google_refresh_token   — долгоживущий токен для обновления access; ENCRYPTED
 * google_access_token    — короткоживущий, кэшируется до expires_at; ENCRYPTED
 * google_token_expires_at — когда access_token протухнет (~1 час)
 * google_connected_at    — когда юзер подключил Gmail
 *
 * Все три токена/email — nullable: юзер может не подключать Gmail, тогда
 * отправка с сайта ему недоступна.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique()->after('id');
            $table->string('google_email')->nullable()->after('google_id');
            // Текстовые поля для шифрованных токенов — длина зашифрованных
            // строк сильно превышает 255 символов, поэтому text.
            $table->text('google_refresh_token')->nullable();
            $table->text('google_access_token')->nullable();
            $table->timestamp('google_token_expires_at')->nullable();
            $table->timestamp('google_connected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn([
                'google_id',
                'google_email',
                'google_refresh_token',
                'google_access_token',
                'google_token_expires_at',
                'google_connected_at',
            ]);
        });
    }
};
