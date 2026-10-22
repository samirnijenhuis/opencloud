<?php

namespace Database\Factories;

use App\Models\ProviderCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderCredential>
 */
class ProviderCredentialFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'digitalocean',
            'token' => 'dop_v1_'.fake()->sha256(),
            'checked_at' => null,
            'missing_rights' => null,
        ];
    }
}
