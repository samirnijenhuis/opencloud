<?php

namespace Tests\Feature\Http;

use App\Models\Deployment;
use App\Models\User;
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

        foreach (['/applications', '/applications/create', '/applications/1', '/environments/1', '/environments/1/deployments', "/deployments/{$deployment->id}", '/environments/1/logs', '/environments/1/env', '/environments/1/domains', '/environments/1/settings', '/clusters', '/settings/audit'] as $path) {
            $this->actingAs($owner)->get($path)->assertOk();
        }
    }
}
