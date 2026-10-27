<?php

namespace App\Domains\Members\Models;

use App\Domains\Members\Enums\Role;
use Database\Factories\InviteFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[UseFactory(InviteFactory::class)]
class Invite extends Model
{
    use HasFactory;

    public const LIFETIME_DAYS = 7;

    protected $fillable = ['token_hash', 'role', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The database keeps only a hash, so the plain token exists only in the return value.
     *
     * @return array{self, string}
     */
    public static function issue(Role $role): array
    {
        $token = Str::random(48);

        $invite = self::create([
            'token_hash' => self::hashToken($token),
            'role' => $role,
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ]);

        return [$invite, $token];
    }

    public static function findValid(string $token): ?self
    {
        return self::query()
            ->where('token_hash', self::hashToken($token))
            ->where('expires_at', '>', now())
            ->first();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
