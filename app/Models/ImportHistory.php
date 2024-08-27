<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'user_id',
        'supplier_code',
        'file_list',
        'folder_path',
        'process_log',
        'status',
    ];

    protected $casts = [
        'file_list' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
