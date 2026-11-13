<?php

namespace Tests\Domains\Clusters;

use App\Domains\Clusters\ClusterPartsManifests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class ClusterPartsManifestsTest extends TestCase
{
    private const LABELS = ['app.kubernetes.io/name' => 'caddy', 'app.kubernetes.io/part-of' => 'opencloud'];

    private const METADATA = ['name' => 'caddy', 'namespace' => 'opencloud', 'labels' => self::LABELS];

    public function test_the_parts_are_two_namespaces_and_caddy_with_a_volume_and_a_load_balancer(): void
    {
        $this->assertSame([
            ['apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => 'opencloud']],
            ['apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => 'oc-datastores']],
            [
                'apiVersion' => 'v1',
                'kind' => 'PersistentVolumeClaim',
                'metadata' => self::METADATA,
                'spec' => ['accessModes' => ['ReadWriteOnce'], 'resources' => ['requests' => ['storage' => '1Gi']]],
            ],
            [
                'apiVersion' => 'apps/v1',
                'kind' => 'Deployment',
                'metadata' => self::METADATA,
                'spec' => [
                    'replicas' => 1,
                    'strategy' => ['type' => 'Recreate'],
                    'selector' => ['matchLabels' => ['app.kubernetes.io/name' => 'caddy']],
                    'template' => [
                        'metadata' => ['labels' => self::LABELS],
                        'spec' => [
                            'containers' => [[
                                'name' => 'caddy',
                                'image' => 'caddy:9.9.9',
                                'imagePullPolicy' => 'IfNotPresent',
                                'command' => ['caddy', 'run', '--resume'],
                                'ports' => [['name' => 'http', 'containerPort' => 80], ['name' => 'https', 'containerPort' => 443]],
                                'readinessProbe' => ['exec' => ['command' => ['nc', '-z', '127.0.0.1', '2019']], 'periodSeconds' => 5],
                                'resources' => ['requests' => ['cpu' => '50m', 'memory' => '64Mi'], 'limits' => ['memory' => '256Mi']],
                                'volumeMounts' => [
                                    ['name' => 'state', 'mountPath' => '/data', 'subPath' => 'data'],
                                    ['name' => 'state', 'mountPath' => '/config', 'subPath' => 'config'],
                                ],
                            ]],
                            'volumes' => [['name' => 'state', 'persistentVolumeClaim' => ['claimName' => 'caddy']]],
                        ],
                    ],
                ],
            ],
            [
                'apiVersion' => 'v1',
                'kind' => 'Service',
                'metadata' => self::METADATA,
                'spec' => [
                    'type' => 'LoadBalancer',
                    'selector' => ['app.kubernetes.io/name' => 'caddy'],
                    'ports' => [
                        ['name' => 'http', 'port' => 80, 'targetPort' => 'http'],
                        ['name' => 'https', 'port' => 443, 'targetPort' => 'https'],
                    ],
                ],
            ],
        ], array_slice((new ClusterPartsManifests('caddy:9.9.9'))->all(), 0, 5));
    }

    public function test_the_log_store_is_the_one_of_the_local_cluster_with_a_volume_in_place_of_the_host_path(): void
    {
        $local = array_map(fn (string $document) => Yaml::parse($document), preg_split('/^---$/m', file_get_contents(dirname(__DIR__, 4).'/platform/logs.yaml')));
        $local[1]['spec']['template']['spec']['volumes'][1] = ['name' => 'data', 'persistentVolumeClaim' => ['claimName' => 'loki']];
        $volume = [
            'apiVersion' => 'v1',
            'kind' => 'PersistentVolumeClaim',
            'metadata' => ['name' => 'loki', 'namespace' => 'opencloud', 'labels' => ['app.kubernetes.io/name' => 'loki', 'app.kubernetes.io/part-of' => 'opencloud']],
            'spec' => ['accessModes' => ['ReadWriteOnce'], 'resources' => ['requests' => ['storage' => '10Gi']]],
        ];

        $this->assertSame([$local[0], $volume, ...array_slice($local, 1)], array_slice((new ClusterPartsManifests('caddy:9.9.9'))->all(), 5));
    }
}
