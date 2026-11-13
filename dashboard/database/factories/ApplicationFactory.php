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
            // A GitHub owner name has no dot, so fake()->userName() can give a repository that RepositoryName::parse() refuses.
            'repository' => fake()->word().'/'.fake()->slug(2),
            'cluster_id' => Cluster::factory(),
            'type' => ApplicationType::LARAVEL,
        ];
    }
}
