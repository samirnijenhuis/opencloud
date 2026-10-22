<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GithubApp extends Model
{
    use HasFactory;

    protected $fillable = ['app_id', 'private_key', 'webhook_secret'];

    protected $hidden = ['private_key', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
        ];
    }
}
