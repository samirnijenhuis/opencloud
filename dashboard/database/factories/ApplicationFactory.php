<?php

namespace Database\Factories;

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\Cluster;
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
            'type' => ApplicationType::Laravel,
        ];
    }
}
