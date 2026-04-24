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
}
