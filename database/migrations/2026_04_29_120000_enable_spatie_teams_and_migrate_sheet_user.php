<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Включаем фичу teams в Spatie и переезжаем со своей pivot-таблицы sheet_user
 * на стандартные таблицы Spatie (model_has_roles с team_id = sheet_id).
 *
 * Соглашение по team_id:
 *   - admin           → team_id = NULL (глобальная роль, проверяется со снятым team-context)
 *   - editor / viewer → team_id = sheet_id (роль действует только на этот лист)
 *
 * PostgreSQL 15+ требуется (используется NULLS NOT DISTINCT для уникальности с NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableNames   = config('permission.table_names');
        $columnNames  = config('permission.column_names');
        $teamFk       = $columnNames['team_foreign_key'] ?? 'team_id';
        $modelKey     = $columnNames['model_morph_key'] ?? 'model_id';
        $rolePivot    = $columnNames['role_pivot_key'] ?? 'role_id';
        $permPivot    = $columnNames['permission_pivot_key'] ?? 'permission_id';

        $rolesTbl     = $tableNames['roles'];
        $modelRoles   = $tableNames['model_has_roles'];
        $modelPerms   = $tableNames['model_has_permissions'];

        // 1. roles: добавить team_id если ещё нет. На fresh-deploy с teams=true
        // оригинальная Spatie миграция уже создаёт колонку — тогда пропускаем.
        if (!Schema::hasColumn($rolesTbl, $teamFk)) {
            Schema::table($rolesTbl, function ($table) use ($teamFk) {
                $table->unsignedBigInteger($teamFk)->nullable()->after('id');
                $table->index($teamFk, 'roles_team_foreign_key_index');
            });
        }

        // 2. model_has_roles: добавить team_id если ещё нет, перестроить уникальность.
        // PG15+ NULLS NOT DISTINCT нужен чтобы (NULL, role, model_id, type) считались
        // равными между собой и не появлялись дубли для admin.
        if (!Schema::hasColumn($modelRoles, $teamFk)) {
            // Для апгрейда с teams=false к teams=true: дропаем старый PK + добавляем колонку.
            DB::statement("ALTER TABLE {$modelRoles} DROP CONSTRAINT IF EXISTS model_has_roles_pkey");
            Schema::table($modelRoles, function ($table) use ($teamFk) {
                $table->unsignedBigInteger($teamFk)->nullable();
                $table->index($teamFk, 'model_has_roles_team_foreign_key_index');
            });
        } else {
            // Колонка уже есть (fresh-deploy с teams=true) — оригинальная миграция
            // уже сделала PK включающий team_id и колонку NOT NULL. Дропаем PK и
            // делаем nullable, чтобы admin (team_id=NULL) мог быть assigned.
            DB::statement("ALTER TABLE {$modelRoles} DROP CONSTRAINT IF EXISTS model_has_roles_pkey");
            DB::statement("ALTER TABLE {$modelRoles} ALTER COLUMN {$teamFk} DROP NOT NULL");
        }
        // Уникальный индекс ставим всегда (если ещё нет).
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS model_has_roles_unique
            ON {$modelRoles} ({$teamFk}, {$rolePivot}, {$modelKey}, model_type)
            NULLS NOT DISTINCT");

        // 3. model_has_permissions: то же самое.
        if (!Schema::hasColumn($modelPerms, $teamFk)) {
            DB::statement("ALTER TABLE {$modelPerms} DROP CONSTRAINT IF EXISTS model_has_permissions_pkey");
            Schema::table($modelPerms, function ($table) use ($teamFk) {
                $table->unsignedBigInteger($teamFk)->nullable();
                $table->index($teamFk, 'model_has_permissions_team_foreign_key_index');
            });
        } else {
            DB::statement("ALTER TABLE {$modelPerms} DROP CONSTRAINT IF EXISTS model_has_permissions_pkey");
            DB::statement("ALTER TABLE {$modelPerms} ALTER COLUMN {$teamFk} DROP NOT NULL");
        }
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS model_has_permissions_unique
            ON {$modelPerms} ({$teamFk}, {$permPivot}, {$modelKey}, model_type)
            NULLS NOT DISTINCT");

        // 4. Создаём роли admin/editor/viewer с team_id=NULL (глобальные определения,
        // assignments будут с team_id=NULL для admin или с team_id=sheet_id для editor/viewer).
        // На fresh-deploy роли создаются здесь; на апгрейде с teams=false — admin уже есть,
        // создаются только editor/viewer.
        $now = now();
        $existingRoles = DB::table($rolesTbl)->whereIn('name', ['admin', 'editor', 'viewer'])->pluck('id', 'name')->all();
        $rolesToInsert = [];
        foreach (['admin', 'editor', 'viewer'] as $name) {
            if (!isset($existingRoles[$name])) {
                $rolesToInsert[] = [
                    'name' => $name,
                    'guard_name' => 'web',
                    $teamFk => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        if (!empty($rolesToInsert)) {
            DB::table($rolesTbl)->insert($rolesToInsert);
        }

        // Перечитать id-шники после вставки.
        $roleIds = DB::table($rolesTbl)
            ->whereIn('name', ['editor', 'viewer'])
            ->whereNull($teamFk)
            ->pluck('id', 'name')
            ->all();

        // 5. Backfill sheet_user → model_has_roles. Только если таблица ещё есть.
        if (Schema::hasTable('sheet_user') && !empty($roleIds['editor']) && !empty($roleIds['viewer'])) {
            $rows = DB::table('sheet_user')->get();
            $batch = [];
            foreach ($rows as $row) {
                $rid = $row->role === 'editor' ? $roleIds['editor'] : ($row->role === 'viewer' ? $roleIds['viewer'] : null);
                if ($rid === null) continue;
                $batch[] = [
                    $rolePivot   => $rid,
                    'model_type' => 'App\\Models\\User',
                    $modelKey    => $row->user_id,
                    $teamFk      => $row->sheet_id,
                ];
            }
            if (!empty($batch)) {
                // insertOrIgnore — на случай повторного запуска миграции в dev.
                DB::table($modelRoles)->insertOrIgnore($batch);
            }
        }

        // 6. Дропаем старую pivot-таблицу sheet_user — данные уже в Spatie.
        Schema::dropIfExists('sheet_user');

        // 7. Сбрасываем кэш Spatie, чтобы поднять новый guard и роли.
        app('cache')
            ->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        $tableNames   = config('permission.table_names');
        $columnNames  = config('permission.column_names');
        $teamFk       = $columnNames['team_foreign_key'] ?? 'team_id';
        $modelKey     = $columnNames['model_morph_key'] ?? 'model_id';
        $rolePivot    = $columnNames['role_pivot_key'] ?? 'role_id';
        $permPivot    = $columnNames['permission_pivot_key'] ?? 'permission_id';

        $rolesTbl     = $tableNames['roles'];
        $modelRoles   = $tableNames['model_has_roles'];
        $modelPerms   = $tableNames['model_has_permissions'];

        // Восстанавливаем sheet_user из model_has_roles ДО удаления team_id.
        Schema::create('sheet_user', function ($table) {
            $table->id();
            $table->foreignId('sheet_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('role')->default('viewer');
            $table->timestamps();
        });

        $editorId = DB::table($rolesTbl)->where('name', 'editor')->whereNull($teamFk)->value('id');
        $viewerId = DB::table($rolesTbl)->where('name', 'viewer')->whereNull($teamFk)->value('id');
        if ($editorId || $viewerId) {
            $rows = DB::table($modelRoles)
                ->whereIn($rolePivot, array_filter([$editorId, $viewerId]))
                ->whereNotNull($teamFk)
                ->get();
            $now = now();
            $back = [];
            foreach ($rows as $r) {
                $role = $r->{$rolePivot} === $editorId ? 'editor' : 'viewer';
                $back[] = [
                    'sheet_id'   => $r->{$teamFk},
                    'user_id'    => $r->{$modelKey},
                    'role'       => $role,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if (!empty($back)) DB::table('sheet_user')->insert($back);
        }

        // Сносим уникальные индексы, потом team_id колонки.
        DB::statement("DROP INDEX IF EXISTS model_has_roles_unique");
        DB::statement("DROP INDEX IF EXISTS model_has_permissions_unique");

        Schema::table($modelRoles, function ($table) use ($teamFk) {
            $table->dropIndex('model_has_roles_team_foreign_key_index');
            $table->dropColumn($teamFk);
        });
        Schema::table($modelPerms, function ($table) use ($teamFk) {
            $table->dropIndex('model_has_permissions_team_foreign_key_index');
            $table->dropColumn($teamFk);
        });
        Schema::table($rolesTbl, function ($table) use ($teamFk) {
            $table->dropIndex('roles_team_foreign_key_index');
            $table->dropColumn($teamFk);
        });

        // Удаляем editor/viewer роли (admin оставляем).
        DB::table($rolesTbl)->whereIn('name', ['editor', 'viewer'])->delete();

        // Возвращаем primary keys на model_has_roles / model_has_permissions.
        DB::statement("ALTER TABLE {$modelRoles}
            ADD CONSTRAINT model_has_roles_pkey
            PRIMARY KEY ({$rolePivot}, {$modelKey}, model_type)");
        DB::statement("ALTER TABLE {$modelPerms}
            ADD CONSTRAINT model_has_permissions_pkey
            PRIMARY KEY ({$permPivot}, {$modelKey}, model_type)");

        app('cache')
            ->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
