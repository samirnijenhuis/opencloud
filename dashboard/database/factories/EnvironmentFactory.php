<?php

namespace Database\Factories;

use App\Enums\EnvironmentKind;
use App\Models\Application;
use App\Models\Environment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Environment>
 */
class EnvironmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'name' => fake()->unique()->slug(1),
            'kind' => EnvironmentKind::Permanent,
            'branch' => 'main',
        ];
    }
}
