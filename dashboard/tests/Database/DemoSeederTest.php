<?php

namespace Tests\Database;

use App\Domains\Deployments\Models\Deployment;
use App\Domains\Members\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_screen_renders_with_the_demo_data(): void
    {
        $this->seed(DemoSeeder::class);
        $owner = User::where('email', 'owner@example.com')->sole();
        $deployment = Deployment::where('status', 'building')->sole();

        $statuses = Deployment::pluck('status')->map->value->unique()->sort()->values()->all();
        $this->assertSame(['building', 'deploying', 'failed', 'live', 'queued', 'superseded'], $statuses);

        foreach (['/applications', '/applications/create', '/applications/1', '/environments/1', '/environments/1/deployments', "/deployments/{$deployment->id}", '/environments/1/logs', '/environments/1/commands', '/environments/1/command-runs', '/command-runs/1', '/environments/2', '/settings/backups', '/environments/1/settings', '/clusters', '/settings/audit'] as $path) {
            $this->actingAs($owner)->get($path)->assertOk();
        }
    }
}
