<?php

namespace Database\Factories;

use App\Enums\DatastoreKind;
use App\Models\Cluster;
use App\Models\Datastore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Datastore>
 */
class DatastoreFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cluster_id' => Cluster::factory(),
            'kind' => DatastoreKind::Mysql,
            'version' => '8.4',
        ];
    }
}
