<?php

namespace Database\Factories;

use App\Domains\Applications\Enums\ApplicationType;
use App\Domains\Applications\Models\Application;
use App\Domains\Clusters\Models\Cluster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'repository' => fake()->userName().'/'.fake()->slug(2),
            'cluster_id' => Cluster::factory(),
            'type' => ApplicationType::LARAVEL,
        ];
    }
}
