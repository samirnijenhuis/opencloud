<?php

namespace App\Domains\Clusters;

use App\Domains\Datastores\DatastoreManifests;

/**
 * The opencloud parts on a cluster other than local: Caddy behind a load balancer, and the datastore namespace.
 * Every object lives in a namespace of opencloud, so other software on the cluster stays as it is. The methods are pure.
 */
final readonly class ClusterPartsManifests
{
    public const NAMESPACE = 'opencloud';

    /** The namespaces that disconnecting with "remove the parts" deletes. */
    public const NAMESPACES = [self::NAMESPACE, DatastoreManifests::NAMESPACE];

    private const CADDY_LABELS = ['app.kubernetes.io/name' => 'caddy', 'app.kubernetes.io/part-of' => 'opencloud'];

    public function __construct(private string $caddyImage) {}

    public static function fromConfig(): self
    {
        return new self(config('opencloud.caddy_image'));
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return [
            ['apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => self::NAMESPACE]],
            DatastoreManifests::namespace(),
            $this->caddyVolume(),
            $this->caddy(),
            $this->caddyService(),
        ];
    }

    /**
     * Certificates and the last config live on a volume, because a managed cluster may move the pod to a node with an empty disk.
     *
     * @return array<string, mixed>
     */
    public function caddyVolume(): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'PersistentVolumeClaim',
            'metadata' => self::metadata(),
            'spec' => [
                'accessModes' => ['ReadWriteOnce'],
                'resources' => ['requests' => ['storage' => '1Gi']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function caddy(): array
    {
        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => self::metadata(),
            'spec' => [
                'replicas' => 1,
                'strategy' => ['type' => 'Recreate'],
                'selector' => ['matchLabels' => ['app.kubernetes.io/name' => 'caddy']],
                'template' => [
                    'metadata' => ['labels' => self::CADDY_LABELS],
                    'spec' => [
                        'containers' => [[
                            'name' => 'caddy',
                            'image' => $this->caddyImage,
                            'imagePullPolicy' => 'IfNotPresent',
                            'command' => ['caddy', 'run', '--resume'],
                            'ports' => [
                                ['name' => 'http', 'containerPort' => 80],
                                ['name' => 'https', 'containerPort' => 443],
                            ],
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
        ];
    }

    /** @return array<string, mixed> */
    public function caddyService(): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => self::metadata(),
            'spec' => [
                'type' => 'LoadBalancer',
                'selector' => ['app.kubernetes.io/name' => 'caddy'],
                'ports' => [
                    ['name' => 'http', 'port' => 80, 'targetPort' => 'http'],
                    ['name' => 'https', 'port' => 443, 'targetPort' => 'https'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function metadata(): array
    {
        return ['name' => 'caddy', 'namespace' => self::NAMESPACE, 'labels' => self::CADDY_LABELS];
    }
}
