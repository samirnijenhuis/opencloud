<?php

namespace Database\Factories;

use App\Models\GithubApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GithubApp>
 */
class GithubAppFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'app_id' => fake()->numberBetween(100000, 999999),
            'private_key' => "-----BEGIN RSA PRIVATE KEY-----\n".fake()->sha256()."\n-----END RSA PRIVATE KEY-----",
            'webhook_secret' => fake()->sha256(),
        ];
    }
}
