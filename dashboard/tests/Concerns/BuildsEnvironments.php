<?php

namespace Tests\Concerns;

use App\Domains\Applications\Models\Application;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Environments\Models\Environment;

trait BuildsEnvironments
{
    protected Cluster $cluster;

    protected function makeCluster(): Cluster
    {
        return $this->cluster ??= Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'name' => 'local', 'ingress_ip' => '203.0.113.10']);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function makeEnvironment(bool $protected = true, string $name = 'production', array $attributes = []): Environment
    {
        $application = Application::factory()->for($this->makeCluster())->create(['name' => 'shop', 'repository' => 'acme/shop']);

        return Environment::factory()->for($application)->create([
            'name' => $name,
            'is_protected' => $protected,
            ...$attributes,
        ]);
    }
}
