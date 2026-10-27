<?php

namespace Database\Factories;

use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnvVar>
 */
class EnvVarFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'environment_id' => Environment::factory(),
            'key' => strtoupper(fake()->unique()->lexify('KEY_????')),
            'value' => fake()->word(),
        ];
    }
}
