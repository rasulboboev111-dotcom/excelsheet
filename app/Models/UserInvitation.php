<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UserInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'token',
        'created_by',
        'revoked_at',
        'uses_count',
    ];

    protected $casts = [
        'revoked_at' => 'datetime',
        'uses_count' => 'integer',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Генерирует токен достаточной длины, чтобы перебор был нереалистичен.
     * 40 символов из алфавита Str::random — это ~238 бит энтропии.
     */
    public static function generateToken(): string
    {
        return Str::random(40);
    }
}
