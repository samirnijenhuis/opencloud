<?php

namespace Database\Factories;

use App\Domains\Applications\Models\Application;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
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
            'kind' => EnvironmentKind::PERMANENT,
            'branch' => 'main',
        ];
    }
}
