<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GithubApp extends Model
{
    use HasFactory;

    protected $fillable = ['app_id', 'slug', 'owner', 'private_key', 'webhook_secret'];

    protected $hidden = ['private_key', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'app_id' => 'integer',
            'private_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
        ];
    }

    public function installUrl(): string
    {
        return "https://github.com/apps/{$this->slug}/installations/new";
    }
}
