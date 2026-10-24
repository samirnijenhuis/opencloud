<?php

namespace App\Kube\Manifests;

use App\Clusters\ClusterRegistry;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Process;
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

    public function autoscales(): bool
    {
        return $this->environment->max_instances > $this->environment->min_instances;
    }

    /** @return list<array<string, mixed>> the process Deployments, the Service, and the autoscaler when it applies */
    public function workloads(Deployment $deployment): array
    {
        return [
            $this->web($deployment),
            ...$this->environment->processes->map(fn (Process $process) => $this->process($process, $deployment)),
            $this->service(),
            ...($this->autoscales() ? [$this->autoscaler()] : []),
        ];
    }

    /**
     * Server-side apply never removes objects, so the autoscaler of a web with a fixed count must be deleted.
     *
     * @return list<array{0: string, 1: string}> kind and name pairs
     */
    public function absent(): array
    {
        return $this->autoscales() ? [] : [['horizontalpodautoscaler', Process::WEB]];
    }

    /**
     * Selects the Deployment of each process that left the list. Server-side apply never removes objects,
     * and the dashboard keeps no record of a removed process, so the selector matches each name that is not in the list.
     */
    public function removedProcessesSelector(): string
    {
        return Labels::ENVIRONMENT.'='.$this->environment->id.','.Labels::PROCESS.','.Labels::PROCESS.' notin ('.implode(',', $this->environment->processNames()).')';
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
            Process::WEB,
            $deployment,
            replicas: $this->autoscales() ? null : $this->environment->min_instances,
            strategy: self::ROLLING,
            container: [
                'ports' => [['name' => 'http', 'containerPort' => $this->environment->application->port]],
                'readinessProbe' => ['httpGet' => ['path' => $this->environment->health_path, 'port' => 'http']],
                // The old pod keeps serving until the Service and Caddy stop sending it new connections.
                'lifecycle' => ['preStop' => ['sleep' => ['seconds' => 10]]],
            ],
        );
    }

    /**
     * The command runs in a shell, so a process can use a normal command line.
     *
     * @return array<string, mixed>
     */
    public function process(Process $process, Deployment $deployment): array
    {
        return $this->processDeployment(
            $process->name,
            $deployment,
            replicas: $process->instances,
            strategy: $process->exclusive ? ['type' => 'Recreate'] : self::ROLLING,
            container: ['command' => ['sh', '-c', $process->command]],
        );
    }

    /** @return array<string, mixed> */
    public function service(): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => $this->metadata(Process::WEB, Labels::process($this->environment, Process::WEB)),
            'spec' => [
                'selector' => Labels::process($this->environment, Process::WEB),
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
            'metadata' => $this->metadata(Process::WEB, Labels::process($this->environment, Process::WEB)),
            'spec' => [
                'scaleTargetRef' => ['apiVersion' => 'apps/v1', 'kind' => 'Deployment', 'name' => Process::WEB],
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
        $labels = Labels::deployment($this->environment, Process::DEPLOY, $deployment);

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
                        'containers' => [$this->container(Process::DEPLOY, $deployment, [
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
    private function processDeployment(string $process, Deployment $deployment, ?int $replicas, array $strategy, array $container): array
    {
        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => $this->metadata($process, Labels::process($this->environment, $process)),
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
    private function container(string $process, Deployment $deployment, array $extra): array
    {
        $size = $this->environment->size;
        $resources = ['cpu' => $size->cpuMillicores().'m', 'memory' => $size->memoryMib().'Mi'];

        return [
            'name' => $process,
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
