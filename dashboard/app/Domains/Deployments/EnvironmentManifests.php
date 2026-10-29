<?php

namespace App\Domains\Deployments;

use App\Domains\Clusters\ClusterPartsManifests;
use App\Domains\Clusters\ClusterRegistry;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\ProcessKind;
use App\Domains\Environments\Enums\Size;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\Process;
use App\Support\Kube\Labels;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Builds the Kubernetes objects of one environment. Each method is pure: models in, a manifest array out.
 */
final readonly class EnvironmentManifests
{
    public const SERVICE_PORT = 8080;

    public const PULL_SECRET = 'opencloud-registry';

    /** The pod settings of every pod of an environment: the deployment pods, the CronJobs, the deploy Job, and the command Job. */
    public const POD_SECURITY = [
        'automountServiceAccountToken' => false,
        'securityContext' => ['seccompProfile' => ['type' => 'RuntimeDefault']],
    ];

    /**
     * The images run as root, and nginx and Apache start as root and then switch to their own user, so the container
     * keeps the default set of capabilities without NET_RAW, MKNOD, AUDIT_WRITE, SETFCAP, SETPCAP, and SYS_CHROOT.
     */
    public const CONTAINER_SECURITY = [
        'allowPrivilegeEscalation' => false,
        'capabilities' => [
            'drop' => ['ALL'],
            'add' => ['CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'FSETID', 'KILL', 'SETGID', 'SETUID', 'NET_BIND_SERVICE'],
        ],
    ];

    private const ROLLING = ['type' => 'RollingUpdate', 'rollingUpdate' => ['maxUnavailable' => 0, 'maxSurge' => 1]];

    public function __construct(private Environment $environment) {}

    public function autoscales(): bool
    {
        return $this->environment->max_instances > $this->environment->min_instances;
    }

    /** @return list<array<string, mixed>> the Deployments of web and the long-running processes, the Service, and the autoscalers */
    public function workloads(Deployment $deployment): array
    {
        return [
            $this->web($deployment),
            ...$this->longRunning()->map(fn (Process $process) => $this->process($process, $deployment)),
            $this->service(),
            ...$this->autoscalers(),
        ];
    }

    /** @return list<array<string, mixed>> one CronJob for each scheduled process */
    public function cronJobs(Deployment $deployment): array
    {
        return $this->scheduled()->map(fn (Process $process) => $this->cronJob($process, $deployment))->values()->all();
    }

    /**
     * Selects the objects of each kind that no process uses now, for example the Deployment of a process that became
     * scheduled, or the autoscaler of a process with a fixed count. Server-side apply never removes objects, and the
     * dashboard keeps no record of a removed process, so each selector matches each name that the kind does not keep.
     *
     * @return array<string, string> the label selector for each Kubernetes kind
     */
    public function strays(): array
    {
        return [
            'deployment' => $this->selectorExcept($this->environment->longRunningNames()),
            'cronjob' => $this->selectorExcept($this->scheduled()->pluck('name')->all()),
            'horizontalpodautoscaler' => $this->selectorExcept(array_map(fn (array $autoscaler) => $autoscaler['metadata']['name'], $this->autoscalers())),
        ];
    }

    /** @param  list<string>  $keep */
    private function selectorExcept(array $keep): string
    {
        $selector = Labels::ENVIRONMENT.'='.$this->environment->id.','.Labels::PROCESS;

        return $keep === [] ? $selector : $selector.','.Labels::PROCESS.' notin ('.implode(',', $keep).')';
    }

    /** @return Collection<int, Process> */
    private function longRunning(): Collection
    {
        return $this->environment->processes->where('kind', ProcessKind::LONG_RUNNING)->values();
    }

    /** @return Collection<int, Process> */
    private function scheduled(): Collection
    {
        return $this->environment->processes->where('kind', ProcessKind::SCHEDULED)->values();
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
     * The pods of an environment take traffic only from their own namespace and from Caddy. They send traffic to every
     * pod, because the ingress policy of the target decides, and to every address except the Kubernetes API. The API
     * runs on the host network, where no ingress policy can guard it. An IPv6 address stays out of the list, because
     * it is not inside 0.0.0.0/0 and Kubernetes then rejects the policy.
     *
     * @param  list<string>  $apiServerIps  the IPv4 addresses of the endpoints of the Service kubernetes
     * @return list<array<string, mixed>>
     */
    public function networkPolicies(array $apiServerIps): array
    {
        $any = new stdClass;
        $internet = ['cidr' => '0.0.0.0/0', ...($apiServerIps === [] ? [] : ['except' => array_map(fn (string $ip) => "{$ip}/32", $apiServerIps)])];

        return [
            [
                'apiVersion' => 'networking.k8s.io/v1',
                'kind' => 'NetworkPolicy',
                'metadata' => $this->metadata('ingress-from-namespace-and-caddy', Labels::environment($this->environment)),
                'spec' => [
                    'podSelector' => $any,
                    'policyTypes' => ['Ingress'],
                    'ingress' => [['from' => [
                        ['podSelector' => $any],
                        [
                            'namespaceSelector' => ['matchLabels' => ['kubernetes.io/metadata.name' => ClusterPartsManifests::NAMESPACE]],
                            'podSelector' => ['matchLabels' => ['app.kubernetes.io/name' => 'caddy']],
                        ],
                    ]]],
                ],
            ],
            [
                'apiVersion' => 'networking.k8s.io/v1',
                'kind' => 'NetworkPolicy',
                'metadata' => $this->metadata('egress-to-pods-and-internet', Labels::environment($this->environment)),
                'spec' => [
                    'podSelector' => $any,
                    'policyTypes' => ['Egress'],
                    'egress' => [['to' => [['namespaceSelector' => $any], ['ipBlock' => $internet]]]],
                ],
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
            size: $this->environment->size,
            container: [
                'ports' => [['name' => 'http', 'containerPort' => $this->environment->application->port]],
                'readinessProbe' => ['httpGet' => ['path' => $this->environment->health_path, 'port' => 'http', ...$this->probeHost()]],
                // The old pod keeps serving until the Service and Caddy stop sending it new connections.
                'lifecycle' => ['preStop' => ['sleep' => ['seconds' => 10]]],
            ],
        );
    }

    /**
     * The kubelet sends the pod address as the Host header. An app that checks the host, for example Django with
     * ALLOWED_HOSTS, refuses it, so the probe sends the first domain of the environment.
     *
     * @return array{httpHeaders?: list<array{name: string, value: string}>}
     */
    private function probeHost(): array
    {
        $hostname = $this->environment->domains->sortBy('id')->first()?->hostname;

        return $hostname === null ? [] : ['httpHeaders' => [['name' => 'Host', 'value' => $hostname]]];
    }

    /**
     * The Deployment of a long-running process. The command runs in a shell, so a process can use a normal command line.
     *
     * @return array<string, mixed>
     */
    public function process(Process $process, Deployment $deployment): array
    {
        return $this->processDeployment(
            $process->name,
            $deployment,
            replicas: self::scales($process) ? null : $process->min_instances,
            size: $process->size,
            container: $this->commandOf($process),
        );
    }

    /**
     * The CronJob of a scheduled process. Its pods get the labels, the image, the env Secret, and the command of a
     * long-running process. Each run starts one pod, and a failed run waits for the next time on the schedule.
     *
     * @return array<string, mixed>
     */
    public function cronJob(Process $process, Deployment $deployment): array
    {
        $labels = Labels::deployment($this->environment, $process->name, $deployment);

        return [
            'apiVersion' => 'batch/v1',
            'kind' => 'CronJob',
            'metadata' => $this->metadata($process->name, Labels::process($this->environment, $process->name)),
            'spec' => [
                'schedule' => $process->schedule,
                // With Allow, a pod that cannot start (a full node) does not stop the next run, so Pending pods pile up
                // each minute and then all start at once. Forbid keeps one run, and the deadline skips a late start.
                'concurrencyPolicy' => 'Forbid',
                'startingDeadlineSeconds' => 60,
                'successfulJobsHistoryLimit' => 1,
                'failedJobsHistoryLimit' => 1,
                'jobTemplate' => [
                    'metadata' => ['labels' => $labels],
                    'spec' => [
                        'backoffLimit' => 0,
                        'template' => [
                            'metadata' => ['labels' => $labels],
                            'spec' => [
                                'restartPolicy' => 'Never',
                                ...$this->imagePullSecrets(),
                                ...self::POD_SECURITY,
                                'containers' => [$this->container($process->name, $deployment, $process->size, $this->commandOf($process))],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * An image with tini as its entrypoint gets only the arguments, so tini stays PID 1 and passes SIGTERM on.
     *
     * @return array<string, list<string>>
     */
    private function commandOf(Process $process): array
    {
        return [$this->environment->application->type->imageRunsTini() ? 'args' : 'command' => self::shellCommand($process->command)];
    }

    private static function scales(Process $process): bool
    {
        return $process->max_instances > $process->min_instances;
    }

    /**
     * Dash, the sh of Debian images, stays PID 1 under `sh -c`, and PID 1 drops a SIGTERM that it has no handler for.
     * So a single command replaces the shell with exec, and a queue worker gets the SIGTERM and finishes its job.
     * A list, a pipe, a subshell, or a leading assignment cannot follow exec, so such a command runs as it is.
     *
     * @return list<string>
     */
    public static function shellCommand(string $command): array
    {
        $single = preg_match('/^(?!exec\s)(?![A-Za-z_][A-Za-z0-9_]*=)[^;&|`()\n]+$/', $command) === 1;

        return ['sh', '-c', $single ? "exec {$command}" : $command];
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

    /** @return list<array<string, mixed>> one HorizontalPodAutoscaler for web and for each long-running process whose maximum is above its minimum */
    public function autoscalers(): array
    {
        $environment = $this->environment;
        $autoscalers = $this->autoscales() ? [$this->autoscaler(Process::WEB, $environment->min_instances, $environment->max_instances, $environment->cpu_target)] : [];

        foreach ($this->longRunning()->filter(self::scales(...)) as $process) {
            $autoscalers[] = $this->autoscaler($process->name, $process->min_instances, $process->max_instances, $process->cpu_target);
        }

        return $autoscalers;
    }

    /** @return array<string, mixed> */
    private function autoscaler(string $process, int $min, int $max, int $cpuTarget): array
    {
        return [
            'apiVersion' => 'autoscaling/v2',
            'kind' => 'HorizontalPodAutoscaler',
            'metadata' => $this->metadata($process, Labels::process($this->environment, $process)),
            'spec' => [
                'scaleTargetRef' => ['apiVersion' => 'apps/v1', 'kind' => 'Deployment', 'name' => $process],
                'minReplicas' => $min,
                'maxReplicas' => $max,
                'metrics' => [[
                    'type' => 'Resource',
                    'resource' => [
                        'name' => 'cpu',
                        'target' => ['type' => 'Utilization', 'averageUtilization' => $cpuTarget],
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
                        ...self::POD_SECURITY,
                        'containers' => [$this->container(Process::DEPLOY, $deployment, $this->environment->size, [
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
     * @param  array<string, mixed>  $container
     * @return array<string, mixed>
     */
    private function processDeployment(string $process, Deployment $deployment, ?int $replicas, Size $size, array $container): array
    {
        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => $this->metadata($process, Labels::process($this->environment, $process)),
            'spec' => [
                ...($replicas !== null ? ['replicas' => $replicas] : []),
                'selector' => ['matchLabels' => Labels::process($this->environment, $process)],
                'strategy' => self::ROLLING,
                'template' => [
                    'metadata' => ['labels' => Labels::deployment($this->environment, $process, $deployment)],
                    'spec' => [...$this->imagePullSecrets(), ...self::POD_SECURITY, 'containers' => [$this->container($process, $deployment, $size, $container)]],
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
    private function container(string $process, Deployment $deployment, Size $size, array $extra): array
    {
        $resources = ['cpu' => $size->cpuMillicores().'m', 'memory' => $size->memoryMib().'Mi'];

        return [
            'name' => $process,
            'image' => $deployment->image,
            ...$extra,
            'envFrom' => [['secretRef' => ['name' => self::secretName($deployment)]]],
            'resources' => ['requests' => $resources, 'limits' => $resources],
            'securityContext' => self::CONTAINER_SECURITY,
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
