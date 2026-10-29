<?php

namespace App\Domains\Clusters;

use App\Domains\Datastores\DatastoreManifests;

/**
 * The opencloud parts on a cluster other than local: Caddy behind a load balancer, the datastore namespace, and the log store.
 * Every object lives in a namespace of opencloud, so other software on the cluster stays as it is. The methods are pure.
 * platform/logs.yaml holds the same log store for the local cluster, and ClusterPartsManifestsTest compares the two.
 */
final readonly class ClusterPartsManifests
{
    public const NAMESPACE = 'opencloud';

    /** The namespaces that disconnecting with "remove the parts" deletes. */
    public const NAMESPACES = [self::NAMESPACE, DatastoreManifests::NAMESPACE];

    /** The Deployment of Loki. ReadLogs runs wget in its query container, because the Loki image has no shell. */
    public const LOKI = 'loki';

    public const LOKI_QUERY_CONTAINER = 'query';

    private const CADDY_LABELS = ['app.kubernetes.io/name' => 'caddy', 'app.kubernetes.io/part-of' => 'opencloud'];

    private const LOKI_IMAGE = 'grafana/loki:3.7.9';

    private const FLUENT_BIT_IMAGE = 'fluent/fluent-bit:5.1.5';

    private const BUSYBOX_IMAGE = 'busybox:1.37.0';

    /** The compactor deletes the chunks of each line that is older than retention_period. */
    private const LOKI_CONFIG = <<<'YAML'
        auth_enabled: false
        server:
          http_listen_port: 3100
          log_level: warn
        common:
          instance_addr: 127.0.0.1
          path_prefix: /loki
          replication_factor: 1
          ring:
            kvstore:
              store: inmemory
          storage:
            filesystem:
              chunks_directory: /loki/chunks
              rules_directory: /loki/rules
        schema_config:
          configs:
            - from: "2026-01-01"
              store: tsdb
              object_store: filesystem
              schema: v13
              index:
                prefix: index_
                period: 24h
        limits_config:
          retention_period: 168h
          discover_service_name: []
          discover_log_levels: false
        compactor:
          working_directory: /loki/compactor
          retention_enabled: true
          delete_request_store: filesystem
        analytics:
          reporting_enabled: false

        YAML;

    /**
     * Reads only the containers of environment namespaces. The file name gives the namespace, the pod, and the container,
     * and the container has the name of its process, so Fluent Bit needs no access to the Kubernetes API.
     * The default Retry_Limit of 1 drops the lines while Loki restarts.
     */
    private const FLUENT_BIT_CONFIG = <<<'CONF'
        [SERVICE]
            Flush            1
            Log_Level        warn
            Parsers_File     parsers.conf

        [INPUT]
            Name             tail
            Path             /var/log/containers/*_oc-env-*_*.log
            Path_Key         file
            Tag              pods.*
            multiline.parser cri
            DB               /var/lib/opencloud/fluent-bit/tail.db
            Read_from_Head   On
            Mem_Buf_Limit    16MB
            Skip_Long_Lines  On
            Refresh_Interval 5

        [FILTER]
            Name             parser
            Match            pods.*
            Key_Name         file
            Parser           pod_file
            Reserve_Data     On

        [OUTPUT]
            Name             loki
            Match            pods.*
            Host             loki
            Port             3100
            Labels           namespace=$namespace, pod=$pod, process=$process
            Remove_Keys      namespace, pod, process, stream, _p, time
            Drop_Single_Key  raw
            Line_Format      key_value
            Retry_Limit      no_limits

        CONF;

    private const FLUENT_BIT_PARSERS = <<<'CONF'
        [PARSER]
            Name   pod_file
            Format regex
            Regex  ^/var/log/containers/(?<pod>[^_]+)_(?<namespace>[^_]+)_(?<process>.+)-[0-9a-f]{64}\.log$

        CONF;

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
            ...$this->logStore(),
        ];
    }

    /**
     * Loki keeps the lines of the apps for 7 days on a volume, and Fluent Bit on each node sends them.
     * Only Fluent Bit may reach Loki, because Loki has no login and holds the unmasked lines of every environment.
     *
     * @return list<array<string, mixed>>
     */
    private function logStore(): array
    {
        $loki = ['app.kubernetes.io/name' => self::LOKI];
        $fluentBit = ['app.kubernetes.io/name' => 'fluent-bit'];

        return [
            [
                'apiVersion' => 'v1',
                'kind' => 'ConfigMap',
                'metadata' => self::part(self::LOKI),
                'data' => ['loki.yaml' => self::LOKI_CONFIG],
            ],
            [
                'apiVersion' => 'v1',
                'kind' => 'PersistentVolumeClaim',
                'metadata' => self::part(self::LOKI),
                'spec' => [
                    'accessModes' => ['ReadWriteOnce'],
                    'resources' => ['requests' => ['storage' => '10Gi']],
                ],
            ],
            [
                'apiVersion' => 'apps/v1',
                'kind' => 'Deployment',
                'metadata' => self::part(self::LOKI),
                'spec' => [
                    'replicas' => 1,
                    'strategy' => ['type' => 'Recreate'],
                    'selector' => ['matchLabels' => $loki],
                    'template' => [
                        'metadata' => ['labels' => self::labels(self::LOKI)],
                        'spec' => [
                            // Loki runs as user 10001, and a new host folder or volume belongs to root.
                            'initContainers' => [[
                                'name' => 'data-owner',
                                'image' => self::BUSYBOX_IMAGE,
                                'imagePullPolicy' => 'IfNotPresent',
                                'command' => ['chown', '10001:10001', '/loki'],
                                'volumeMounts' => [['name' => 'data', 'mountPath' => '/loki']],
                            ]],
                            'containers' => [
                                [
                                    'name' => self::LOKI,
                                    'image' => self::LOKI_IMAGE,
                                    'imagePullPolicy' => 'IfNotPresent',
                                    'args' => ['-config.file=/etc/loki/loki.yaml'],
                                    'ports' => [['name' => 'http', 'containerPort' => 3100]],
                                    'readinessProbe' => ['httpGet' => ['path' => '/ready', 'port' => 'http'], 'periodSeconds' => 10],
                                    'resources' => ['requests' => ['cpu' => '50m', 'memory' => '128Mi'], 'limits' => ['memory' => '512Mi']],
                                    'volumeMounts' => [
                                        ['name' => 'config', 'mountPath' => '/etc/loki'],
                                        ['name' => 'data', 'mountPath' => '/loki'],
                                    ],
                                ],
                                [
                                    'name' => self::LOKI_QUERY_CONTAINER,
                                    'image' => self::BUSYBOX_IMAGE,
                                    'imagePullPolicy' => 'IfNotPresent',
                                    'command' => ['sh', '-c', 'trap "exit 0" TERM; while true; do sleep 3600 & wait; done'],
                                    'resources' => ['requests' => ['cpu' => '5m', 'memory' => '8Mi'], 'limits' => ['memory' => '32Mi']],
                                ],
                            ],
                            'volumes' => [
                                ['name' => 'config', 'configMap' => ['name' => self::LOKI]],
                                ['name' => 'data', 'persistentVolumeClaim' => ['claimName' => self::LOKI]],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'apiVersion' => 'v1',
                'kind' => 'Service',
                'metadata' => self::part(self::LOKI),
                'spec' => [
                    'selector' => $loki,
                    'ports' => [['name' => 'http', 'port' => 3100, 'targetPort' => 'http']],
                ],
            ],
            [
                'apiVersion' => 'networking.k8s.io/v1',
                'kind' => 'NetworkPolicy',
                'metadata' => [...self::part(self::LOKI), 'name' => 'loki-from-fluent-bit'],
                'spec' => [
                    'podSelector' => ['matchLabels' => $loki],
                    'policyTypes' => ['Ingress'],
                    'ingress' => [[
                        'from' => [['podSelector' => ['matchLabels' => $fluentBit]]],
                        'ports' => [['protocol' => 'TCP', 'port' => 3100]],
                    ]],
                ],
            ],
            [
                'apiVersion' => 'v1',
                'kind' => 'ConfigMap',
                'metadata' => self::part('fluent-bit'),
                'data' => ['fluent-bit.conf' => self::FLUENT_BIT_CONFIG, 'parsers.conf' => self::FLUENT_BIT_PARSERS],
            ],
            [
                'apiVersion' => 'apps/v1',
                'kind' => 'DaemonSet',
                'metadata' => self::part('fluent-bit'),
                'spec' => [
                    'selector' => ['matchLabels' => $fluentBit],
                    'template' => [
                        'metadata' => ['labels' => self::labels('fluent-bit')],
                        'spec' => [
                            'containers' => [[
                                'name' => 'fluent-bit',
                                'image' => self::FLUENT_BIT_IMAGE,
                                'imagePullPolicy' => 'IfNotPresent',
                                'command' => ['/fluent-bit/bin/fluent-bit', '-c', '/fluent-bit/etc/opencloud/fluent-bit.conf'],
                                'resources' => ['requests' => ['cpu' => '20m', 'memory' => '32Mi'], 'limits' => ['memory' => '128Mi']],
                                'volumeMounts' => [
                                    ['name' => 'config', 'mountPath' => '/fluent-bit/etc/opencloud'],
                                    ['name' => 'pod-logs', 'mountPath' => '/var/log', 'readOnly' => true],
                                    ['name' => 'positions', 'mountPath' => '/var/lib/opencloud/fluent-bit'],
                                ],
                            ]],
                            'volumes' => [
                                ['name' => 'config', 'configMap' => ['name' => 'fluent-bit']],
                                ['name' => 'pod-logs', 'hostPath' => ['path' => '/var/log']],
                                ['name' => 'positions', 'hostPath' => ['path' => '/var/lib/opencloud/fluent-bit', 'type' => 'DirectoryOrCreate']],
                            ],
                        ],
                    ],
                ],
            ],
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

    /** @return array<string, mixed> */
    private static function part(string $name): array
    {
        return ['name' => $name, 'namespace' => self::NAMESPACE, 'labels' => self::labels($name)];
    }

    /** @return array<string, string> */
    private static function labels(string $name): array
    {
        return ['app.kubernetes.io/name' => $name, 'app.kubernetes.io/part-of' => 'opencloud'];
    }
}
