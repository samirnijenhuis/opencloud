<?php

namespace App\Kube\Manifests;

use App\Clusters\ClusterRegistry;
use App\Enums\ApplicationType;
use App\Models\Deployment;
use App\Models\Environment;
use stdClass;

/**
 * Builds the Kubernetes objects of one environment. Each method is pure: models in, a manifest array out.
 */
final readonly class EnvironmentManifests
{
    public const SERVICE_PORT = 8080;

    public const PULL_SECRET = 'opencloud-registry';

    private const ROLLING = ['type' => 'RollingUpdate', 'rollingUpdate' => ['maxUnavailable' => 0, 'maxSurge' => 1]];

    public function __construct(private Environment $environment) {}

    /** @return list<Process> the processes that run as Kubernetes Deployments, web first */
    public function processes(): array
    {
        $laravel = $this->environment->application->type === ApplicationType::Laravel;

        return array_values(array_filter([
            Process::Web,
            $laravel && $this->environment->worker_enabled ? Process::Worker : null,
            $laravel && $this->environment->scheduler_enabled ? Process::Scheduler : null,
        ]));
    }

    public function autoscales(): bool
    {
        return $this->environment->max_instances > $this->environment->min_instances;
    }

    /** @return list<array<string, mixed>> the process Deployments, the Service, and the autoscaler when it applies */
    public function workloads(Deployment $deployment): array
    {
        return [
            ...array_map(fn (Process $process) => match ($process) {
                Process::Web => $this->web($deployment),
                Process::Worker => $this->worker($deployment),
                Process::Scheduler => $this->scheduler($deployment),
            }, $this->processes()),
            $this->service(),
            ...($this->autoscales() ? [$this->autoscaler()] : []),
        ];
    }

    /**
     * Server-side apply never removes objects, so a switched-off process must be deleted.
     *
     * @return list<array{0: string, 1: string}> kind and name pairs
     */
    public function absent(): array
    {
        $running = $this->processes();
        $absent = [];

        foreach ([Process::Worker, Process::Scheduler] as $process) {
            if (! in_array($process, $running, true)) {
                $absent[] = ['deployment', $process->value];
            }
        }

        if (! $this->autoscales()) {
            $absent[] = ['horizontalpodautoscaler', 'web'];
        }

        return $absent;
    }

    /** @return array<string, mixed> */
    public function namespace(): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Namespace',
            'metadata' => [
                'name' => $this->environment->kubeNamespace(),
                'labels' => Labels::environment($this->environment),
            ],
        ];
    }

    /**
     * Each deployment gets its own Secret, so a failed deployment never changes the env of the live pods.
     * The values go in `data`, not `stringData`. Server-side apply does not remove a key that leaves `stringData`.
     *
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    public function secret(Deployment $deployment, array $env): array
    {
        $data = array_map(base64_encode(...), $env);

        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => $this->metadata(self::secretName($deployment), [...Labels::environment($this->environment), Labels::DEPLOYMENT => (string) $deployment->id]),
            'type' => 'Opaque',
            'data' => $data === [] ? new stdClass : $data,
        ];
    }

    /**
     * The credentials for the registry of the cluster. Each pod of the environment pulls its image with them.
     *
     * @return array<string, mixed>
     */
    public function pullSecret(ClusterRegistry $registry): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => $this->metadata(self::PULL_SECRET, Labels::environment($this->environment)),
            'type' => 'kubernetes.io/dockerconfigjson',
            'data' => ['.dockerconfigjson' => base64_encode(json_encode($registry->dockerConfig(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))],
        ];
    }

    /** @return array<string, mixed> */
    public function web(Deployment $deployment): array
    {
        return $this->processDeployment(
            Process::Web,
            $deployment,
            replicas: $this->autoscales() ? null : $this->environment->min_instances,
            strategy: self::ROLLING,
            container: [
                'ports' => [['name' => 'http', 'containerPort' => $this->environment->application->port]],
                'readinessProbe' => ['httpGet' => ['path' => $this->environment->health_path, 'port' => 'http']],
            ],
        );
    }

    /** @return array<string, mixed> */
    public function worker(Deployment $deployment): array
    {
        return $this->processDeployment(
            Process::Worker,
            $deployment,
            replicas: 1,
            strategy: self::ROLLING,
            container: ['command' => ['php', 'artisan', 'queue:work', '--sleep=3', '--tries=3', '--max-time=3600']],
        );
    }

    /** @return array<string, mixed> */
    public function scheduler(Deployment $deployment): array
    {
        return $this->processDeployment(
            Process::Scheduler,
            $deployment,
            replicas: 1,
            strategy: ['type' => 'Recreate'],
            container: ['command' => ['php', 'artisan', 'schedule:work']],
        );
    }

    /** @return array<string, mixed> */
    public function service(): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => $this->metadata('web', Labels::process($this->environment, Process::Web)),
            'spec' => [
                'selector' => Labels::process($this->environment, Process::Web),
                'ports' => [['name' => 'http', 'port' => self::SERVICE_PORT, 'targetPort' => 'http']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function autoscaler(): array
    {
        return [
            'apiVersion' => 'autoscaling/v2',
            'kind' => 'HorizontalPodAutoscaler',
            'metadata' => $this->metadata('web', Labels::process($this->environment, Process::Web)),
            'spec' => [
                'scaleTargetRef' => ['apiVersion' => 'apps/v1', 'kind' => 'Deployment', 'name' => 'web'],
                'minReplicas' => $this->environment->min_instances,
                'maxReplicas' => $this->environment->max_instances,
                'metrics' => [[
                    'type' => 'Resource',
                    'resource' => [
                        'name' => 'cpu',
                        'target' => ['type' => 'Utilization', 'averageUtilization' => $this->environment->cpu_target],
                    ],
                ]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function deployJob(Deployment $deployment): array
    {
        $labels = Labels::deployment($this->environment, Process::Deploy, $deployment);

        return [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => $this->metadata(self::jobName($deployment), $labels),
            'spec' => [
                'backoffLimit' => 0,
                'activeDeadlineSeconds' => 900,
                'ttlSecondsAfterFinished' => 3600,
                'template' => [
                    'metadata' => ['labels' => $labels],
                    'spec' => [
                        'restartPolicy' => 'Never',
                        ...$this->imagePullSecrets(),
                        'containers' => [$this->container(Process::Deploy, $deployment, [
                            'command' => ['sh', '-c', implode(' && ', $this->environment->deploy_commands)],
                        ])],
                    ],
                ],
            ],
        ];
    }

    public static function jobName(Deployment $deployment): string
    {
        return "deploy-{$deployment->id}";
    }

    public static function secretName(Deployment $deployment): string
    {
        return "env-{$deployment->id}";
    }

    /**
     * @param  array<string, mixed>  $strategy
     * @param  array<string, mixed>  $container
     * @return array<string, mixed>
     */
    private function processDeployment(Process $process, Deployment $deployment, ?int $replicas, array $strategy, array $container): array
    {
        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => $this->metadata($process->value, Labels::process($this->environment, $process)),
            'spec' => [
                ...($replicas !== null ? ['replicas' => $replicas] : []),
                'selector' => ['matchLabels' => Labels::process($this->environment, $process)],
                'strategy' => $strategy,
                'template' => [
                    'metadata' => ['labels' => Labels::deployment($this->environment, $process, $deployment)],
                    'spec' => [...$this->imagePullSecrets(), 'containers' => [$this->container($process, $deployment, $container)]],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function imagePullSecrets(): array
    {
        return $this->environment->application->cluster->registry() === null ? [] : ['imagePullSecrets' => [['name' => self::PULL_SECRET]]];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function container(Process $process, Deployment $deployment, array $extra): array
    {
        $size = $this->environment->size;
        $resources = ['cpu' => $size->cpuMillicores().'m', 'memory' => $size->memoryMib().'Mi'];

        return [
            'name' => $process->value,
            'image' => $deployment->image,
            ...$extra,
            'envFrom' => [['secretRef' => ['name' => self::secretName($deployment)]]],
            'resources' => ['requests' => $resources, 'limits' => $resources],
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, mixed>
     */
    private function metadata(string $name, array $labels): array
    {
        return ['name' => $name, 'namespace' => $this->environment->kubeNamespace(), 'labels' => $labels];
    }
}
