<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SheetData extends Model
{
    use HasFactory;

    protected $table = 'sheet_data';

    protected $fillable = ['sheet_id', 'row_data', 'row_index'];

    protected $casts = [
        'row_data' => 'array',
    ];

    public function sheet()
    {
        return $this->belongsTo(Sheet::class);
    }
}
