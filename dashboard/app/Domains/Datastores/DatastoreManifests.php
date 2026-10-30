<?php

namespace App\Domains\Datastores;

use App\Domains\Backups\Models\BackupTarget;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Support\Kube\Labels;
use stdClass;

/**
 * Builds the Kubernetes objects of the standard datastores in the namespace oc-datastores. Each method is pure.
 */
final class DatastoreManifests
{
    public const NAMESPACE = 'oc-datastores';

    /** The release pins these versions. A new datastore stores its version, and its image tag comes from that. */
    public const VERSIONS = [
        'mysql' => '8.4',
        'postgres' => '17',
        'valkey' => '9.1',
        'rustfs' => '1.0.3',
    ];

    private const IMAGES = [
        'mysql' => 'mysql',
        'postgres' => 'postgres',
        'valkey' => 'valkey/valkey',
        'rustfs' => 'rustfs/rustfs',
    ];

    public const RC_IMAGE = 'rustfs/rc:v0.1.36';

    public const RCLONE_IMAGE = 'rclone/rclone:1.75.2';

    public const RUSTFS_ROOT_USER = 'opencloud';

    /** A StatefulSet cannot change its volume claim later, so the size is fixed here and not in config. */
    private const SERVER_VOLUME = '10Gi';

    private const VALKEY_VOLUME = '1Gi';

    public static function serverName(Datastore $datastore): string
    {
        return "{$datastore->kind->value}-{$datastore->id}";
    }

    /** The one pod of a server StatefulSet. */
    public static function serverPod(Datastore $datastore): string
    {
        return self::serverName($datastore).'-0';
    }

    public static function valkeyName(DatastoreAttachment $attachment): string
    {
        return "valkey-{$attachment->id}";
    }

    public static function host(string $name): string
    {
        return "{$name}.".self::NAMESPACE.'.svc.cluster.local';
    }

    /** @return array<string, mixed> */
    public static function namespace(): array
    {
        return ['apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => self::NAMESPACE]];
    }

    /**
     * The datastores take traffic only from their own namespace, where the bucket copy Job runs, and from the namespaces
     * of the environments. The dashboard needs no rule, because it reaches a datastore with `kubectl exec`.
     *
     * @return array<string, mixed>
     */
    public static function networkPolicy(): array
    {
        return [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'NetworkPolicy',
            'metadata' => self::metadata('ingress-from-environments', []),
            'spec' => [
                'podSelector' => new stdClass,
                'policyTypes' => ['Ingress'],
                'ingress' => [['from' => [
                    ['podSelector' => new stdClass],
                    ['namespaceSelector' => ['matchExpressions' => [['key' => Labels::ENVIRONMENT, 'operator' => 'Exists']]]],
                ]]],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function mysql(Datastore $datastore): array
    {
        $name = self::serverName($datastore);

        return [
            self::secret($name, self::datastoreLabels($datastore), ['MYSQL_ROOT_PASSWORD' => $datastore->root_password]),
            self::service($name, self::datastoreLabels($datastore), 'mysql', 3306),
            self::statefulSet($datastore, [
                'containers' => [[
                    'name' => 'mysql',
                    'image' => self::image($datastore),
                    'envFrom' => [['secretRef' => ['name' => $name]]],
                    'ports' => [['name' => 'mysql', 'containerPort' => 3306]],
                    'readinessProbe' => ['tcpSocket' => ['port' => 'mysql'], 'periodSeconds' => 5],
                    'volumeMounts' => [['name' => 'data', 'mountPath' => '/var/lib/mysql']],
                    'resources' => self::memory('256Mi', '2Gi'),
                ]],
            ]),
        ];
    }

    /**
     * The first start runs a server without TCP, so the TCP probe passes only for the real server.
     *
     * @return list<array<string, mixed>>
     */
    public static function postgres(Datastore $datastore): array
    {
        $name = self::serverName($datastore);

        return [
            self::secret($name, self::datastoreLabels($datastore), ['POSTGRES_PASSWORD' => $datastore->root_password]),
            self::service($name, self::datastoreLabels($datastore), 'postgres', 5432),
            self::statefulSet($datastore, [
                'containers' => [[
                    'name' => 'postgres',
                    'image' => self::image($datastore),
                    'env' => [['name' => 'PGDATA', 'value' => '/var/lib/postgresql/data/pgdata']],
                    'envFrom' => [['secretRef' => ['name' => $name]]],
                    'ports' => [['name' => 'postgres', 'containerPort' => 5432]],
                    'readinessProbe' => ['tcpSocket' => ['port' => 'postgres'], 'periodSeconds' => 5],
                    'volumeMounts' => [['name' => 'data', 'mountPath' => '/var/lib/postgresql/data']],
                    'resources' => self::memory('128Mi', '2Gi'),
                ]],
            ]),
        ];
    }

    /**
     * The RustFS image has no admin client, so the pod also runs `rc` for `kubectl exec`.
     *
     * @return list<array<string, mixed>>
     */
    public static function rustfs(Datastore $datastore): array
    {
        $name = self::serverName($datastore);
        $credentials = [['secretRef' => ['name' => $name]]];

        return [
            self::secret($name, self::datastoreLabels($datastore), [
                'RUSTFS_ACCESS_KEY' => self::RUSTFS_ROOT_USER,
                'RUSTFS_SECRET_KEY' => $datastore->root_password,
            ]),
            self::service($name, self::datastoreLabels($datastore), 's3', 9000),
            self::statefulSet($datastore, [
                'securityContext' => ['fsGroup' => 10001],
                'containers' => [
                    [
                        'name' => 'rustfs',
                        'image' => self::image($datastore),
                        'env' => [
                            ['name' => 'RUSTFS_VOLUMES', 'value' => '/data'],
                            ['name' => 'RUSTFS_ADDRESS', 'value' => ':9000'],
                            ['name' => 'RUSTFS_CONSOLE_ENABLE', 'value' => 'false'],
                        ],
                        'envFrom' => $credentials,
                        'ports' => [['name' => 's3', 'containerPort' => 9000]],
                        'readinessProbe' => ['tcpSocket' => ['port' => 's3'], 'periodSeconds' => 5],
                        'volumeMounts' => [['name' => 'data', 'mountPath' => '/data']],
                        'resources' => self::memory('128Mi', '1Gi'),
                    ],
                    [
                        'name' => 'rc',
                        'image' => self::RC_IMAGE,
                        'command' => ['sleep', '2147483647'],
                        'envFrom' => $credentials,
                        'resources' => self::memory('16Mi', '128Mi'),
                    ],
                ],
            ]),
        ];
    }

    /**
     * Valkey has no tenant isolation worth trusting, so each attachment gets its own small server.
     *
     * @return list<array<string, mixed>>
     */
    public static function valkey(DatastoreAttachment $attachment): array
    {
        $name = self::valkeyName($attachment);
        $labels = self::attachmentLabels($attachment);

        return [
            self::secret($name, $labels, ['VALKEY_PASSWORD' => $attachment->password]),
            [
                'apiVersion' => 'v1',
                'kind' => 'PersistentVolumeClaim',
                'metadata' => self::metadata($name, $labels),
                'spec' => self::volumeSpec(self::VALKEY_VOLUME),
            ],
            self::service($name, $labels, 'valkey', 6379),
            [
                'apiVersion' => 'apps/v1',
                'kind' => 'Deployment',
                'metadata' => self::metadata($name, $labels),
                'spec' => [
                    'replicas' => 1,
                    'selector' => ['matchLabels' => $labels],
                    'strategy' => ['type' => 'Recreate'],
                    'template' => [
                        'metadata' => ['labels' => $labels],
                        'spec' => [
                            'containers' => [[
                                'name' => 'valkey',
                                'image' => self::image($attachment->datastore),
                                // The default policy noeviction makes a full Valkey refuse a write, so it never drops a queued job.
                                'args' => ['valkey-server', '--requirepass', '$(VALKEY_PASSWORD)', '--appendonly', 'yes', '--dir', '/data', '--maxmemory', '256mb'],
                                'env' => [['name' => 'VALKEY_PASSWORD', 'valueFrom' => ['secretKeyRef' => ['name' => $name, 'key' => 'VALKEY_PASSWORD']]]],
                                'ports' => [['name' => 'valkey', 'containerPort' => 6379]],
                                'readinessProbe' => ['tcpSocket' => ['port' => 'valkey'], 'periodSeconds' => 5],
                                'volumeMounts' => [['name' => 'data', 'mountPath' => '/data']],
                                'resources' => self::memory('32Mi', '512Mi'),
                            ]],
                            'volumes' => [['name' => 'data', 'persistentVolumeClaim' => ['claimName' => $name]]],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * A one-off Job that copies every bucket of one RustFS server to the backup target, one folder per bucket.
     *
     * @return list<array<string, mixed>>
     */
    public static function copyBuckets(Datastore $datastore, BackupTarget $target, string $date): array
    {
        $name = self::serverName($datastore);
        $labels = self::datastoreLabels($datastore);
        $fromSecret = fn (string $secret, string $key) => ['secretKeyRef' => ['name' => $secret, 'key' => $key]];

        return [
            self::secret('backup-target', [], ['ACCESS_KEY' => $target->access_key, 'SECRET_KEY' => $target->secret_key]),
            [
                'apiVersion' => 'batch/v1',
                'kind' => 'Job',
                'metadata' => self::metadata("{$name}-copy-{$date}", $labels),
                'spec' => [
                    'backoffLimit' => 2,
                    'ttlSecondsAfterFinished' => 86400,
                    'template' => [
                        'metadata' => ['labels' => $labels],
                        'spec' => [
                            'restartPolicy' => 'Never',
                            'containers' => [[
                                'name' => 'rclone',
                                'image' => self::RCLONE_IMAGE,
                                'args' => ['sync', 'source:', "target:{$target->bucket}/rustfs/{$datastore->id}"],
                                'env' => [
                                    ['name' => 'RCLONE_CONFIG_SOURCE_TYPE', 'value' => 's3'],
                                    ['name' => 'RCLONE_CONFIG_SOURCE_PROVIDER', 'value' => 'Other'],
                                    ['name' => 'RCLONE_CONFIG_SOURCE_ENDPOINT', 'value' => 'http://'.self::host($name).':9000'],
                                    ['name' => 'RCLONE_CONFIG_SOURCE_ACCESS_KEY_ID', 'valueFrom' => $fromSecret($name, 'RUSTFS_ACCESS_KEY')],
                                    ['name' => 'RCLONE_CONFIG_SOURCE_SECRET_ACCESS_KEY', 'valueFrom' => $fromSecret($name, 'RUSTFS_SECRET_KEY')],
                                    ['name' => 'RCLONE_CONFIG_TARGET_TYPE', 'value' => 's3'],
                                    ['name' => 'RCLONE_CONFIG_TARGET_PROVIDER', 'value' => 'Other'],
                                    ['name' => 'RCLONE_CONFIG_TARGET_ENDPOINT', 'value' => $target->endpoint],
                                    ['name' => 'RCLONE_CONFIG_TARGET_ACCESS_KEY_ID', 'valueFrom' => $fromSecret('backup-target', 'ACCESS_KEY')],
                                    ['name' => 'RCLONE_CONFIG_TARGET_SECRET_ACCESS_KEY', 'valueFrom' => $fromSecret('backup-target', 'SECRET_KEY')],
                                    ['name' => 'RCLONE_CONFIG_TARGET_FORCE_PATH_STYLE', 'value' => 'true'],
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $podSpec
     * @return array<string, mixed>
     */
    private static function statefulSet(Datastore $datastore, array $podSpec): array
    {
        $name = self::serverName($datastore);
        $labels = self::datastoreLabels($datastore);

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'StatefulSet',
            'metadata' => self::metadata($name, $labels),
            'spec' => [
                'serviceName' => $name,
                'replicas' => 1,
                'selector' => ['matchLabels' => $labels],
                'template' => ['metadata' => ['labels' => $labels], 'spec' => $podSpec],
                'volumeClaimTemplates' => [['metadata' => ['name' => 'data'], 'spec' => self::volumeSpec(self::SERVER_VOLUME)]],
            ],
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    private static function secret(string $name, array $labels, array $values): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => self::metadata($name, $labels),
            'type' => 'Opaque',
            'data' => array_map(base64_encode(...), $values),
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, mixed>
     */
    private static function service(string $name, array $labels, string $portName, int $port): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => self::metadata($name, $labels),
            'spec' => [
                'selector' => $labels,
                'ports' => [['name' => $portName, 'port' => $port, 'targetPort' => $portName]],
            ],
        ];
    }

    /**
     * No CPU limit: a datastore that finds spare CPU should use it. The memory limit stops one app from filling the node.
     *
     * @return array{requests: array{memory: string}, limits: array{memory: string}}
     */
    private static function memory(string $request, string $limit): array
    {
        return ['requests' => ['memory' => $request], 'limits' => ['memory' => $limit]];
    }

    /** @return array<string, mixed> */
    private static function volumeSpec(string $size): array
    {
        return ['accessModes' => ['ReadWriteOnce'], 'resources' => ['requests' => ['storage' => $size]]];
    }

    private static function image(Datastore $datastore): string
    {
        return self::IMAGES[$datastore->kind->value].':'.$datastore->version;
    }

    /** @return array<string, string> */
    private static function datastoreLabels(Datastore $datastore): array
    {
        return [Labels::DATASTORE => (string) $datastore->id];
    }

    /** @return array<string, string> */
    private static function attachmentLabels(DatastoreAttachment $attachment): array
    {
        return [
            Labels::DATASTORE => (string) $attachment->datastore_id,
            Labels::ATTACHMENT => (string) $attachment->id,
            Labels::ENVIRONMENT => (string) $attachment->environment_id,
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, mixed>
     */
    private static function metadata(string $name, array $labels): array
    {
        return ['name' => $name, 'namespace' => self::NAMESPACE, ...($labels !== [] ? ['labels' => $labels] : [])];
    }
}
