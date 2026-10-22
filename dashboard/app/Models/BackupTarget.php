<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BackupTarget extends Model
{
    use HasFactory;

    protected $fillable = ['endpoint', 'bucket', 'access_key', 'secret_key'];

    protected $hidden = ['access_key', 'secret_key'];

    protected function casts(): array
    {
        return [
            'access_key' => 'encrypted',
            'secret_key' => 'encrypted',
        ];
    }
}
