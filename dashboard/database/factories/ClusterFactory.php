<?php

namespace Database\Factories;

use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cluster>
 */
class ClusterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'kind' => ClusterKind::CONNECTED,
            'provider' => null,
            'kubeconfig' => "apiVersion: v1\nkind: Config\n",
        ];
    }
}
