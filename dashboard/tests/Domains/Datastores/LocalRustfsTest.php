<?php

namespace Tests\Domains\Datastores;

use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Routing\CaddyConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalRustfsTest extends TestCase
{
    use RefreshDatabase;

    public function test_caddy_routes_the_address_of_each_public_bucket_on_the_cluster_to_its_bucket(): void
    {
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'ingress_ip' => '203.0.113.5']);
        $rustfs = Datastore::factory()->for($cluster)->create(['id' => 6, 'kind' => DatastoreKind::RUSTFS, 'source' => DatastoreSource::STANDARD]);
        DatastoreAttachment::factory()->create(['datastore_id' => $rustfs->id, 'database_name' => 'env-7', 'is_public' => true]);
        DatastoreAttachment::factory()->create(['datastore_id' => $rustfs->id, 'database_name' => 'env-8', 'is_public' => false]);
        $spaces = Datastore::factory()->for($cluster)->create(['kind' => DatastoreKind::RUSTFS, 'source' => DatastoreSource::MANAGED]);
        DatastoreAttachment::factory()->create(['datastore_id' => $spaces->id, 'database_name' => 'shop-env-9-abcdefgh', 'is_public' => true]);
        $other = Datastore::factory()->for(Cluster::factory()->create(['ingress_ip' => '198.51.100.2']))->create(['kind' => DatastoreKind::RUSTFS, 'source' => DatastoreSource::STANDARD]);
        DatastoreAttachment::factory()->create(['datastore_id' => $other->id, 'database_name' => 'env-10', 'is_public' => true]);

        $this->assertSame([
            ['env-7.files.203.0.113.5.sslip.io', '/env-7{http.request.uri.path}', 'rustfs-6.oc-datastores.svc.cluster.local:9000'],
        ], $this->bucketRoutes($cluster));

        $cluster->update(['ingress_ip' => null]);
        $this->assertSame([], $this->bucketRoutes($cluster));
    }

    /** @return list<array{0: string, 1: string, 2: string}> the host, the path, and the upstream of each route with a rewrite */
    private function bucketRoutes(Cluster $cluster): array
    {
        $routes = (new CaddyConfig('internal', null))->forCluster($cluster)['apps']['http']['servers']['apps']['routes'];

        return array_values(array_map(
            fn (array $route) => [$route['match'][0]['host'][0], $route['handle'][0]['uri'], $route['handle'][1]['upstreams'][0]['dial']],
            array_filter($routes, fn (array $route) => $route['handle'][0]['handler'] === 'rewrite'),
        ));
    }
}
