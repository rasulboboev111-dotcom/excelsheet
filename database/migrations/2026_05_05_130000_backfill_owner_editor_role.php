<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: каждому owner'у листа выдаём явную per-sheet роль 'editor',
 * если её ещё нет. Нужно потому, что Sheet::canEdit / canView перестали
 * молча возвращать true для isOwnedBy — теперь admin может реально отозвать
 * права у owner'а через permissions UI, а доступ к листу определяется ТОЛЬКО
 * наличием editor/viewer-роли (или глобальной admin-роли).
 *
 * Без этой миграции после деплоя все существующие owner'ы потеряют доступ
 * к собственным листам — пока admin вручную не выставит им editor.
 *
 * Админов скипаем — они всё равно проходят canEdit через userIsAdmin, и
 * SheetPermissionController запрещает назначать per-sheet роли админам.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableNames  = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $teamFk      = $columnNames['team_foreign_key'] ?? 'team_id';
        $modelKey    = $columnNames['model_morph_key']  ?? 'model_id';
        $rolePivot   = $columnNames['role_pivot_key']   ?? 'role_id';

        $editorId = DB::table($tableNames['roles'])
            ->where('name', 'editor')
            ->whereNull($teamFk)
            ->value('id');

        if (!$editorId) {
            // Роль editor должна быть создана более ранней миграцией Spatie.
            // Если её нет — выходим без ошибки: при следующем деплое порядок
            // миграций исправит ситуацию.
            return;
        }

        $adminRoleId = DB::table($tableNames['roles'])
            ->where('name', 'admin')
            ->whereNull($teamFk)
            ->value('id');

        // ID всех админов — пропускаем при backfill'е.
        $adminUserIds = $adminRoleId
            ? DB::table($tableNames['model_has_roles'])
                ->where($rolePivot, $adminRoleId)
                ->whereNull($teamFk)
                ->where('model_type', \App\Models\User::class)
                ->pluck($modelKey)
                ->all()
            : [];

        $sheets = DB::table('sheets')->select('id', 'user_id')->get();
        $now = now();
        $inserted = 0;

        foreach ($sheets as $sheet) {
            if (!$sheet->user_id) continue;
            if (in_array((int) $sheet->user_id, array_map('intval', $adminUserIds), true)) continue;

            // insertOrIgnore по UNIQUE (model_type, model_id, role_id, team_id)
            // в Spatie — если уже есть, ничего не делает.
            $affected = DB::table($tableNames['model_has_roles'])->insertOrIgnore([
                $rolePivot   => $editorId,
                'model_type' => \App\Models\User::class,
                $modelKey    => $sheet->user_id,
                $teamFk      => $sheet->id,
            ]);
            $inserted += $affected;
        }

        // Сбрасываем кэш Spatie, иначе новые роли не подцепятся до перезапуска.
        app('Spatie\Permission\PermissionRegistrar')->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Не откатываем: rollback вернул бы код к версии с isOwnedBy-shortcut'ом,
        // и owner'ы снова получили бы доступ автоматически. Удаление роли при
        // откате только сломало бы доступ для тех листов, где админ за время
        // эксплуатации сознательно оставил editor у owner'а.
    }
};
