<?php

namespace Database\Factories;

use App\Domains\Environments\Enums\ToolKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\Tool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tool>
 */
class ToolFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'environment_id' => Environment::factory(),
            'kind' => ToolKind::MAILPIT,
            'hostname' => 'mail-'.fake()->unique()->numberBetween(1, 99999).'.previews.example.com',
        ];
    }
}
