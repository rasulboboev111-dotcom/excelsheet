<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * При удалении юзера чистим его ролевые назначения в Spatie-таблицах вручную.
     * model_has_roles / model_has_permissions — морфические таблицы, у model_id
     * нет FK с ON DELETE CASCADE (by design Spatie). Без этого хука после удаления
     * юзера остаются осиротевшие строки, и если когда-нибудь появится новый юзер
     * с тем же id — он унаследует чужие права.
     */
    protected static function booted(): void
    {
        static::deleting(function (User $user) {
            $tableNames = config('permission.table_names');
            $modelKey   = config('permission.column_names.model_morph_key') ?? 'model_id';
            DB::table($tableNames['model_has_roles'])
                ->where($modelKey, $user->id)
                ->where('model_type', User::class)
                ->delete();
            DB::table($tableNames['model_has_permissions'])
                ->where($modelKey, $user->id)
                ->where('model_type', User::class)
                ->delete();
        });
    }
}
