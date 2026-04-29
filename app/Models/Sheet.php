<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Лист (рабочая страница Excel-документа).
 *
 * Права доступа реализованы через Spatie Permission с включённой фичей teams:
 *   - Глобальная роль admin (team_id = NULL) — полный доступ ко всему.
 *   - Per-sheet роли editor / viewer — назначение хранится в model_has_roles
 *     с team_id = sheet_id (то есть «эта роль действует только на этот лист»).
 *
 * Ниже — единственное место в приложении, которое знает про этот мэппинг.
 */
class Sheet extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'user_id', 'order', 'columns'];

    protected $casts = [
        'columns' => 'array',
    ];

    public function data()
    {
        return $this->hasMany(SheetData::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isOwnedBy(?int $userId): bool
    {
        return $userId !== null && (int) $this->user_id === (int) $userId;
    }

    /**
     * Прямой запрос в model_has_roles. Не использует Spatie-relation, потому что
     * та фильтрует по getPermissionsTeamId() — для перебора нескольких листов это
     * означает дёрганье setPermissionsTeamId N раз и сброс кэша. Прямой SQL быстрее
     * и понятнее.
     *
     * Возвращает 'owner' / 'editor' / 'viewer' / null.
     */
    public function userRole(?int $userId): ?string
    {
        if ($userId === null) return null;
        if ($this->isOwnedBy($userId)) return 'owner';

        $tableNames  = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $teamFk      = $columnNames['team_foreign_key'] ?? 'team_id';
        $modelKey    = $columnNames['model_morph_key']  ?? 'model_id';
        $rolePivot   = $columnNames['role_pivot_key']   ?? 'role_id';

        $row = DB::table($tableNames['model_has_roles'].' as mhr')
            ->join($tableNames['roles'].' as r', 'r.id', '=', 'mhr.'.$rolePivot)
            ->where('mhr.'.$teamFk, $this->id)
            ->where('mhr.'.$modelKey, $userId)
            ->where('mhr.model_type', User::class)
            ->whereIn('r.name', ['editor', 'viewer'])
            ->select('r.name')
            ->first();

        return $row?->name;
    }

    public function canEdit(?int $userId): bool
    {
        if ($userId === null) return false;

        $user = User::find($userId);
        if (!$user) return false;

        // Глобальный admin — проверяем со снятым team-context (team_id = NULL).
        // hasGlobalRole — наша обёртка над Spatie с правильным сбросом team_id.
        if (self::userIsAdmin($user)) return true;

        if ($this->isOwnedBy($userId)) return true;

        return $this->userRole($userId) === 'editor';
    }

    public function canView(?int $userId): bool
    {
        if ($userId === null) return false;

        $user = User::find($userId);
        if (!$user) return false;

        if (self::userIsAdmin($user)) return true;
        if ($this->isOwnedBy($userId)) return true;

        $role = $this->userRole($userId);
        return $role === 'viewer' || $role === 'editor';
    }

    /**
     * Spatie с teams=true фильтрует hasRole по текущему team-context. Глобальный admin
     * хранится с team_id=NULL → нужно временно сбросить team_id на null. Делаем это
     * в одном месте, чтобы остальные части кода не путались.
     */
    public static function userIsAdmin(User $user): bool
    {
        $registrar = app(PermissionRegistrar::class);
        $prev = $registrar->getPermissionsTeamId();
        try {
            $registrar->setPermissionsTeamId(null);
            $user->unsetRelation('roles'); // сбросить кэш relation, иначе старый team-фильтр
            return $user->hasRole('admin');
        } finally {
            $registrar->setPermissionsTeamId($prev);
            $user->unsetRelation('roles');
        }
    }

    /**
     * Все юзеры, у которых есть per-sheet роль на этом листе. Возвращает
     * Eloquent-collection с pivot-полем 'role' для совместимости с Vue-компонентом.
     */
    public function assignedUsers()
    {
        $tableNames  = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $teamFk      = $columnNames['team_foreign_key'] ?? 'team_id';
        $modelKey    = $columnNames['model_morph_key']  ?? 'model_id';
        $rolePivot   = $columnNames['role_pivot_key']   ?? 'role_id';

        return DB::table($tableNames['model_has_roles'].' as mhr')
            ->join($tableNames['roles'].' as r', 'r.id', '=', 'mhr.'.$rolePivot)
            ->join('users as u', 'u.id', '=', 'mhr.'.$modelKey)
            ->where('mhr.'.$teamFk, $this->id)
            ->where('mhr.model_type', User::class)
            ->whereIn('r.name', ['editor', 'viewer'])
            ->select('u.id', 'u.name', 'u.email', 'r.name as role')
            ->get();
    }

    /**
     * Выдать юзеру per-sheet роль (editor/viewer/none). 'none' = снять.
     * Возвращает true если изменилось состояние.
     */
    public function setUserRole(int $userId, string $role): bool
    {
        if (!in_array($role, ['editor', 'viewer', 'none'], true)) {
            throw new \InvalidArgumentException("Unknown role: $role");
        }

        $tableNames  = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $teamFk      = $columnNames['team_foreign_key'] ?? 'team_id';
        $modelKey    = $columnNames['model_morph_key']  ?? 'model_id';
        $rolePivot   = $columnNames['role_pivot_key']   ?? 'role_id';

        $editorId = DB::table($tableNames['roles'])->where('name', 'editor')->whereNull($teamFk)->value('id');
        $viewerId = DB::table($tableNames['roles'])->where('name', 'viewer')->whereNull($teamFk)->value('id');

        // Сначала снимаем все текущие per-sheet роли этого юзера на этом листе.
        $deleted = DB::table($tableNames['model_has_roles'])
            ->where($teamFk, $this->id)
            ->where($modelKey, $userId)
            ->where('model_type', User::class)
            ->whereIn($rolePivot, [$editorId, $viewerId])
            ->delete();

        if ($role === 'none') {
            self::flushPermissionCache();
            return $deleted > 0;
        }

        $rid = $role === 'editor' ? $editorId : $viewerId;
        DB::table($tableNames['model_has_roles'])->insertOrIgnore([
            $rolePivot   => $rid,
            'model_type' => User::class,
            $modelKey    => $userId,
            $teamFk      => $this->id,
        ]);
        self::flushPermissionCache();
        return true;
    }

    /**
     * Сносит все per-sheet роли на этом листе. Вызывается перед удалением листа,
     * потому что team_id — это просто колонка, а не FK с cascade.
     */
    public function detachAllAssignments(): void
    {
        $tableNames  = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $teamFk      = $columnNames['team_foreign_key'] ?? 'team_id';

        DB::table($tableNames['model_has_roles'])
            ->where($teamFk, $this->id)
            ->delete();

        DB::table($tableNames['model_has_permissions'])
            ->where($teamFk, $this->id)
            ->delete();

        self::flushPermissionCache();
    }

    /** Сброс кэша Spatie. Вызывать после любой мутации ролей. */
    public static function flushPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Назначить юзеру глобальную роль admin (team_id = NULL).
     * Гарантирует правильный team-context, чтобы случайно не привязать к листу.
     */
    public static function makeUserAdmin(User $user): void
    {
        $registrar = app(PermissionRegistrar::class);
        $prev = $registrar->getPermissionsTeamId();
        try {
            $registrar->setPermissionsTeamId(null);
            $user->unsetRelation('roles');
            if (!$user->hasRole('admin')) {
                $user->assignRole('admin');
            }
        } finally {
            $registrar->setPermissionsTeamId($prev);
            $user->unsetRelation('roles');
        }
    }

    public static function removeUserAdmin(User $user): void
    {
        $registrar = app(PermissionRegistrar::class);
        $prev = $registrar->getPermissionsTeamId();
        try {
            $registrar->setPermissionsTeamId(null);
            $user->unsetRelation('roles');
            if ($user->hasRole('admin')) {
                $user->removeRole('admin');
            }
        } finally {
            $registrar->setPermissionsTeamId($prev);
            $user->unsetRelation('roles');
        }
    }
}
