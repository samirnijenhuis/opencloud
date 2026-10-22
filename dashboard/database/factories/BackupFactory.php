<?php

namespace Database\Factories;

use App\Models\Backup;
use App\Models\Datastore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Backup>
 */
class BackupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'datastore_id' => Datastore::factory(),
            'location' => 'local://backups/'.fake()->uuid().'.sql.gz',
            'size' => fake()->numberBetween(1024, 10485760),
            'status' => 'done',
        ];
    }
}
