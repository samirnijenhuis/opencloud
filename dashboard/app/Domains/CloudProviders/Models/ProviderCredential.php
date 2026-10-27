<?php

namespace App\Domains\CloudProviders\Models;

use App\Domains\CloudProviders\Policies\ProviderCredentialPolicy;
use Database\Factories\ProviderCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(ProviderCredentialFactory::class)]
#[UsePolicy(ProviderCredentialPolicy::class)]
class ProviderCredential extends Model
{
    use HasFactory;

    protected $fillable = ['provider', 'token', 'checked_at', 'missing_rights', 'details'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'checked_at' => 'datetime',
            'missing_rights' => 'array',
            'details' => 'array',
        ];
    }
}
