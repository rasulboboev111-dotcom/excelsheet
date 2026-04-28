<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    public function users()
    {
        return $this->belongsToMany(User::class, 'sheet_user')->withPivot('role')->withTimestamps();
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isOwnedBy(?int $userId): bool
    {
        return $userId !== null && (int) $this->user_id === (int) $userId;
    }

    public function userRole(?int $userId): ?string
    {
        if ($userId === null) return null;
        if ($this->isOwnedBy($userId)) return 'owner';
        $pivot = $this->users()->where('users.id', $userId)->first();
        return $pivot ? $pivot->pivot->role : null;
    }

    public function canEdit(?int $userId): bool
    {
        if ($userId === null) return false;
        // Админ редактирует любой лист.
        $user = User::find($userId);
        if ($user && $user->hasRole('admin')) return true;

        $role = $this->userRole($userId);
        return $role === 'owner' || $role === 'editor';
    }

    public function canView(?int $userId): bool
    {
        if ($userId === null) return false;
        // Админ видит всё.
        $user = User::find($userId);
        if ($user && $user->hasRole('admin')) return true;

        // Иначе — нужна явная pivot-роль (viewer или editor) на этом листе.
        $role = $this->userRole($userId);
        return $role === 'viewer' || $role === 'editor' || $role === 'owner';
    }
}
