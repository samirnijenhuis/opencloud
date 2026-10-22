<?php

namespace Database\Factories;

use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use App\Models\Environment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatastoreAttachment>
 */
class DatastoreAttachmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'datastore_id' => Datastore::factory(),
            'environment_id' => Environment::factory(),
            'database_name' => fake()->unique()->lexify('db_??????'),
            'username' => fake()->unique()->lexify('user_??????'),
            'password' => fake()->password(24, 32),
        ];
    }
}
