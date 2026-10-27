<?php

namespace Database\Factories;

use App\Domains\Environments\Enums\ProcessKind;
use App\Domains\Environments\Enums\Size;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\Process;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Process>
 */
class ProcessFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'environment_id' => Environment::factory(),
            'name' => 'worker',
            'kind' => ProcessKind::LONG_RUNNING,
            'command' => 'php artisan queue:work',
            'schedule' => null,
            'size' => Size::S,
            'min_instances' => 1,
            'max_instances' => 1,
            'cpu_target' => 70,
        ];
    }

    public function scheduled(string $schedule = '* * * * *'): static
    {
        return $this->state([
            'name' => 'scheduler',
            'kind' => ProcessKind::SCHEDULED,
            'command' => 'php artisan schedule:run',
            'schedule' => $schedule,
            'min_instances' => null,
            'max_instances' => null,
            'cpu_target' => null,
        ]);
    }
}
