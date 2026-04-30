<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spatie permission 'send-mail' — глобальная (team_id=NULL),
 * управляет тем, может ли юзер:
 *   1) подключать свой Gmail в /profile
 *   2) видеть кнопку «Отправить» в Dashboard
 *   3) реально отправлять через POST /sheets/{id}/email
 *
 * По умолчанию — НИКТО, кроме админов. Администраторы имеют право автоматически
 * (admin role перебивает любую permission-проверку).
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $teamFk = $columnNames['team_foreign_key'] ?? 'team_id';

        // Создаём permission если ещё нет (idempotent для fresh-deploy).
        $exists = DB::table($tableNames['permissions'])
            ->where('name', 'send-mail')
            ->where('guard_name', 'web')
            ->exists();

        if (!$exists) {
            DB::table($tableNames['permissions'])->insert([
                'name' => 'send-mail',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Сбрасываем кэш Spatie чтобы новый permission был виден сразу.
        app('Spatie\Permission\PermissionRegistrar')->forgetCachedPermissions();
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names');
        DB::table($tableNames['permissions'])
            ->where('name', 'send-mail')
            ->where('guard_name', 'web')
            ->delete();

        app('Spatie\Permission\PermissionRegistrar')->forgetCachedPermissions();
    }
};
