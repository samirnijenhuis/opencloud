<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Invite;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invite>
 */
class InviteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token_hash' => Invite::hashToken(Str::random(48)),
            'role' => Role::Developer,
            'expires_at' => now()->addDays(Invite::LIFETIME_DAYS),
        ];
    }
}
