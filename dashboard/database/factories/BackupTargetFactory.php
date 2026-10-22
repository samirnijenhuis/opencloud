<?php

namespace Database\Factories;

use App\Models\BackupTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupTarget>
 */
class BackupTargetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'endpoint' => 'https://s3.example.com',
            'bucket' => fake()->slug(2),
            'access_key' => fake()->regexify('[A-Z0-9]{20}'),
            'secret_key' => fake()->regexify('[A-Za-z0-9]{40}'),
        ];
    }
}
