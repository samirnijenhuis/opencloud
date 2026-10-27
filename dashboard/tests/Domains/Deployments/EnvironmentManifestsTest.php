<?php

namespace Tests\Domains\Deployments;

use App\Domains\Applications\Enums\ApplicationType;
use App\Domains\Applications\Models\Application;
use App\Domains\Clusters\ClusterRegistry;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Deployments\EnvironmentManifests;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\ProcessKind;
use App\Domains\Environments\Enums\Size;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\Process;
use App\Domains\Routing\Models\Domain;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;
use stdClass;

class EnvironmentManifestsTest extends TestCase
{
    private const WEB_LABELS = [
        'opencloud.dev/application' => '3',
        'opencloud.dev/environment' => '7',
        'opencloud.dev/process' => 'web',
    ];

    private const RESOURCES = [
        'requests' => ['cpu' => '500m', 'memory' => '512Mi'],
        'limits' => ['cpu' => '500m', 'memory' => '512Mi'],
    ];

    private const XS_RESOURCES = [
        'requests' => ['cpu' => '250m', 'memory' => '256Mi'],
        'limits' => ['cpu' => '250m', 'memory' => '256Mi'],
    ];

    public function test_the_namespace_carries_the_environment_labels(): void
    {
        $this->assertSame([
            'apiVersion' => 'v1',
            'kind' => 'Namespace',
            'metadata' => [
                'name' => 'oc-env-7',
                'labels' => ['opencloud.dev/application' => '3', 'opencloud.dev/environment' => '7'],
            ],
        ], $this->manifests()->namespace());
    }

    public function test_the_secret_of_a_deployment_holds_the_env_base64_encoded(): void
    {
        $this->assertSame([
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => [
                'name' => 'env-12',
                'namespace' => 'oc-env-7',
                'labels' => ['opencloud.dev/application' => '3', 'opencloud.dev/environment' => '7', 'opencloud.dev/deployment' => '12'],
            ],
            'type' => 'Opaque',
            'data' => ['APP_ENV' => 'cHJvZHVjdGlvbg==', 'DB_PASSWORD' => 'czNjcjN0'],
        ], $this->manifests()->secret($this->deployment(), ['APP_ENV' => 'production', 'DB_PASSWORD' => 's3cr3t']));
    }

    public function test_an_empty_secret_encodes_data_as_a_json_object(): void
    {
        $secret = $this->manifests()->secret($this->deployment(), []);

        $this->assertEquals(new stdClass, $secret['data']);
        $this->assertStringContainsString('"data":{}', json_encode($secret));
    }

    public function test_the_web_deployment_with_fixed_replicas(): void
    {
        $this->assertSame([
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => ['name' => 'web', 'namespace' => 'oc-env-7', 'labels' => self::WEB_LABELS],
            'spec' => [
                'replicas' => 2,
                'selector' => ['matchLabels' => self::WEB_LABELS],
                'strategy' => ['type' => 'RollingUpdate', 'rollingUpdate' => ['maxUnavailable' => 0, 'maxSurge' => 1]],
                'template' => [
                    'metadata' => ['labels' => [...self::WEB_LABELS, 'opencloud.dev/deployment' => '12']],
                    'spec' => ['containers' => [[
                        'name' => 'web',
                        'image' => 'registry.opencloud.svc.cluster.local:5000/3/7:abc1234-12',
                        'ports' => [['name' => 'http', 'containerPort' => 8080]],
                        'readinessProbe' => ['httpGet' => ['path' => '/up', 'port' => 'http']],
                        'lifecycle' => ['preStop' => ['sleep' => ['seconds' => 10]]],
                        'envFrom' => [['secretRef' => ['name' => 'env-12']]],
                        'resources' => self::RESOURCES,
                    ]]],
                ],
            ],
        ], $this->manifests(['min_instances' => 2, 'max_instances' => 2])->web($this->deployment()));
    }

    public function test_the_web_deployment_leaves_replicas_to_the_autoscaler_and_uses_the_application_port(): void
    {
        $web = $this->manifests(['min_instances' => 1, 'max_instances' => 4, 'size' => Size::M, 'health_path' => '/health'], port: 3000)
            ->web($this->deployment());

        $this->assertArrayNotHasKey('replicas', $web['spec']);
        $this->assertSame([
            'name' => 'web',
            'image' => 'registry.opencloud.svc.cluster.local:5000/3/7:abc1234-12',
            'ports' => [['name' => 'http', 'containerPort' => 3000]],
            'readinessProbe' => ['httpGet' => ['path' => '/health', 'port' => 'http']],
            'lifecycle' => ['preStop' => ['sleep' => ['seconds' => 10]]],
            'envFrom' => [['secretRef' => ['name' => 'env-12']]],
            'resources' => [
                'requests' => ['cpu' => '1000m', 'memory' => '1024Mi'],
                'limits' => ['cpu' => '1000m', 'memory' => '1024Mi'],
            ],
        ], $web['spec']['template']['spec']['containers'][0]);
    }

    public function test_a_long_running_process_runs_its_command_in_a_shell_with_its_own_size_and_instances(): void
    {
        $labels = ['opencloud.dev/application' => '3', 'opencloud.dev/environment' => '7', 'opencloud.dev/process' => 'reverb'];
        $reverb = self::process(['name' => 'reverb', 'command' => 'php artisan reverb:start --host=0.0.0.0 --port=8080', 'size' => Size::XS, 'min_instances' => 3, 'max_instances' => 3]);

        $this->assertSame([
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => ['name' => 'reverb', 'namespace' => 'oc-env-7', 'labels' => $labels],
            'spec' => [
                'replicas' => 3,
                'selector' => ['matchLabels' => $labels],
                'strategy' => ['type' => 'RollingUpdate', 'rollingUpdate' => ['maxUnavailable' => 0, 'maxSurge' => 1]],
                'template' => [
                    'metadata' => ['labels' => [...$labels, 'opencloud.dev/deployment' => '12']],
                    'spec' => ['containers' => [[
                        'name' => 'reverb',
                        'image' => 'registry.opencloud.svc.cluster.local:5000/3/7:abc1234-12',
                        'command' => ['sh', '-c', 'exec php artisan reverb:start --host=0.0.0.0 --port=8080'],
                        'envFrom' => [['secretRef' => ['name' => 'env-12']]],
                        'resources' => self::XS_RESOURCES,
                    ]]],
                ],
            ],
        ], $this->manifests(['size' => Size::L])->process($reverb, $this->deployment()));
    }

    public function test_a_scheduled_process_runs_as_a_cron_job_that_starts_each_run_without_a_wait(): void
    {
        $labels = ['opencloud.dev/application' => '3', 'opencloud.dev/environment' => '7', 'opencloud.dev/process' => 'scheduler'];
        $pods = [...$labels, 'opencloud.dev/deployment' => '12'];

        $this->assertSame([
            'apiVersion' => 'batch/v1',
            'kind' => 'CronJob',
            'metadata' => ['name' => 'scheduler', 'namespace' => 'oc-env-7', 'labels' => $labels],
            'spec' => [
                'schedule' => '* * * * *',
                'concurrencyPolicy' => 'Allow',
                'successfulJobsHistoryLimit' => 1,
                'failedJobsHistoryLimit' => 1,
                'jobTemplate' => [
                    'metadata' => ['labels' => $pods],
                    'spec' => [
                        'backoffLimit' => 0,
                        'template' => [
                            'metadata' => ['labels' => $pods],
                            'spec' => [
                                'restartPolicy' => 'Never',
                                'containers' => [[
                                    'name' => 'scheduler',
                                    'image' => 'registry.opencloud.svc.cluster.local:5000/3/7:abc1234-12',
                                    'command' => ['sh', '-c', 'exec php artisan schedule:run'],
                                    'envFrom' => [['secretRef' => ['name' => 'env-12']]],
                                    'resources' => self::XS_RESOURCES,
                                ]],
                            ],
                        ],
                    ],
                ],
            ],
        ], $this->manifests(['size' => Size::L])->cronJob(self::scheduler(['size' => Size::XS]), $this->deployment()));
    }

    public function test_the_service_listens_on_8080_and_targets_the_named_port(): void
    {
        $this->assertSame([
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => ['name' => 'web', 'namespace' => 'oc-env-7', 'labels' => self::WEB_LABELS],
            'spec' => [
                'selector' => self::WEB_LABELS,
                'ports' => [['name' => 'http', 'port' => 8080, 'targetPort' => 'http']],
            ],
        ], $this->manifests(port: 3000)->service());
    }

    public function test_the_autoscaler_scales_web_on_cpu(): void
    {
        $this->assertSame([[
            'apiVersion' => 'autoscaling/v2',
            'kind' => 'HorizontalPodAutoscaler',
            'metadata' => ['name' => 'web', 'namespace' => 'oc-env-7', 'labels' => self::WEB_LABELS],
            'spec' => [
                'scaleTargetRef' => ['apiVersion' => 'apps/v1', 'kind' => 'Deployment', 'name' => 'web'],
                'minReplicas' => 2,
                'maxReplicas' => 5,
                'metrics' => [[
                    'type' => 'Resource',
                    'resource' => ['name' => 'cpu', 'target' => ['type' => 'Utilization', 'averageUtilization' => 60]],
                ]],
            ],
        ]], $this->manifests(['min_instances' => 2, 'max_instances' => 5, 'cpu_target' => 60])->autoscalers());
    }

    public function test_a_long_running_process_with_a_range_of_instances_gets_its_own_autoscaler_and_leaves_the_replicas_to_it(): void
    {
        $labels = ['opencloud.dev/application' => '3', 'opencloud.dev/environment' => '7', 'opencloud.dev/process' => 'worker'];
        $worker = self::process(['min_instances' => 1, 'max_instances' => 3, 'cpu_target' => 80]);
        $manifests = $this->manifests(processes: [$worker, self::process(['name' => 'horizon', 'min_instances' => 2, 'max_instances' => 2]), self::scheduler()]);

        $this->assertSame([[
            'apiVersion' => 'autoscaling/v2',
            'kind' => 'HorizontalPodAutoscaler',
            'metadata' => ['name' => 'worker', 'namespace' => 'oc-env-7', 'labels' => $labels],
            'spec' => [
                'scaleTargetRef' => ['apiVersion' => 'apps/v1', 'kind' => 'Deployment', 'name' => 'worker'],
                'minReplicas' => 1,
                'maxReplicas' => 3,
                'metrics' => [[
                    'type' => 'Resource',
                    'resource' => ['name' => 'cpu', 'target' => ['type' => 'Utilization', 'averageUtilization' => 80]],
                ]],
            ],
        ]], $manifests->autoscalers());
        $this->assertArrayNotHasKey('replicas', $manifests->process($worker, $this->deployment())['spec']);
    }

    public function test_the_deploy_job_runs_the_commands_once(): void
    {
        $labels = [
            'opencloud.dev/application' => '3',
            'opencloud.dev/environment' => '7',
            'opencloud.dev/process' => 'deploy',
            'opencloud.dev/deployment' => '12',
        ];

        $this->assertSame([
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => ['name' => 'deploy-12', 'namespace' => 'oc-env-7', 'labels' => $labels],
            'spec' => [
                'backoffLimit' => 0,
                'activeDeadlineSeconds' => 900,
                'ttlSecondsAfterFinished' => 3600,
                'template' => [
                    'metadata' => ['labels' => $labels],
                    'spec' => [
                        'restartPolicy' => 'Never',
                        'containers' => [[
                            'name' => 'deploy',
                            'image' => 'registry.opencloud.svc.cluster.local:5000/3/7:abc1234-12',
                            'command' => ['sh', '-c', 'php artisan migrate --force && php artisan db:seed --force'],
                            'envFrom' => [['secretRef' => ['name' => 'env-12']]],
                            'resources' => self::RESOURCES,
                        ]],
                    ],
                ],
            ],
        ], $this->manifests(['deploy_commands' => ['php artisan migrate --force', 'php artisan db:seed --force']])->deployJob($this->deployment()));
    }

    public function test_the_pull_secret_holds_the_registry_credentials_as_a_docker_config(): void
    {
        $secret = $this->manifests()->pullSecret(new ClusterRegistry('192.0.2.5:5000/team', 'robot', 'pull-pass', insecure: true));

        $this->assertSame([
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => [
                'name' => 'opencloud-registry',
                'namespace' => 'oc-env-7',
                'labels' => ['opencloud.dev/application' => '3', 'opencloud.dev/environment' => '7'],
            ],
            'type' => 'kubernetes.io/dockerconfigjson',
        ], array_diff_key($secret, ['data' => true]));
        $this->assertSame(
            '{"auths":{"192.0.2.5:5000":{"username":"robot","password":"pull-pass","auth":"cm9ib3Q6cHVsbC1wYXNz"}}}',
            base64_decode($secret['data']['.dockerconfigjson']),
        );
    }

    public function test_pods_on_a_cluster_with_a_registry_pull_with_the_pull_secret(): void
    {
        $manifests = $this->manifests(['deploy_commands' => ['php artisan migrate --force']], cluster: $this->clusterWithRegistry());
        $deployment = new Deployment(['image' => '192.0.2.5:5000/team/3/7:abc1234-12']);
        $deployment->id = 12;

        $web = $manifests->web($deployment)['spec']['template']['spec'];
        $job = $manifests->deployJob($deployment)['spec']['template']['spec'];

        $this->assertSame([['name' => 'opencloud-registry']], $web['imagePullSecrets']);
        $this->assertSame('192.0.2.5:5000/team/3/7:abc1234-12', $web['containers'][0]['image']);
        $this->assertSame([['name' => 'opencloud-registry']], $job['imagePullSecrets']);
        $this->assertSame([['name' => 'opencloud-registry']], $manifests->process(self::process(), $deployment)['spec']['template']['spec']['imagePullSecrets']);
        $this->assertSame([['name' => 'opencloud-registry']], $manifests->cronJob(self::scheduler(), $deployment)['spec']['jobTemplate']['spec']['template']['spec']['imagePullSecrets']);
        $this->assertSame(['containers'], array_keys($this->manifests()->web($deployment)['spec']['template']['spec']), 'The local cluster pulls without a secret.');
    }

    public function test_each_kind_of_process_gets_its_own_object_and_the_selectors_find_each_object_that_no_process_uses(): void
    {
        $manifests = $this->manifests(processes: [self::process(), self::scheduler(), self::process(['name' => 'horizon', 'max_instances' => 2])]);

        $this->assertSame(
            [['Deployment', 'web'], ['Deployment', 'worker'], ['Deployment', 'horizon'], ['Service', 'web'], ['HorizontalPodAutoscaler', 'horizon']],
            array_map(fn (array $m) => [$m['kind'], $m['metadata']['name']], $manifests->workloads($this->deployment())),
        );
        $this->assertSame([['CronJob', 'scheduler']], array_map(fn (array $m) => [$m['kind'], $m['metadata']['name']], $manifests->cronJobs($this->deployment())));
        $this->assertSame([
            'deployment' => 'opencloud.dev/environment=7,opencloud.dev/process,opencloud.dev/process notin (web,worker,horizon)',
            'cronjob' => 'opencloud.dev/environment=7,opencloud.dev/process,opencloud.dev/process notin (scheduler)',
            'horizontalpodautoscaler' => 'opencloud.dev/environment=7,opencloud.dev/process,opencloud.dev/process notin (horizon)',
        ], $manifests->strays());
    }

    public function test_without_processes_only_web_runs_and_an_autoscaled_web_gets_an_autoscaler(): void
    {
        $manifests = $this->manifests(['max_instances' => 3]);

        $this->assertSame(
            [['Deployment', 'web'], ['Service', 'web'], ['HorizontalPodAutoscaler', 'web']],
            array_map(fn (array $m) => [$m['kind'], $m['metadata']['name']], $manifests->workloads($this->deployment())),
        );
        $this->assertSame([], $manifests->cronJobs($this->deployment()));
        $this->assertSame([
            'deployment' => 'opencloud.dev/environment=7,opencloud.dev/process,opencloud.dev/process notin (web)',
            'cronjob' => 'opencloud.dev/environment=7,opencloud.dev/process',
            'horizontalpodautoscaler' => 'opencloud.dev/environment=7,opencloud.dev/process,opencloud.dev/process notin (web)',
        ], $manifests->strays());
    }

    public function test_a_dockerfile_app_runs_its_own_processes(): void
    {
        $queue = self::process(['name' => 'queue', 'command' => 'node queue.js', 'min_instances' => 2, 'max_instances' => 2]);
        $manifests = $this->manifests(type: ApplicationType::DOCKERFILE, processes: [$queue]);

        $workloads = $manifests->workloads($this->deployment());

        $this->assertSame(
            [['Deployment', 'web'], ['Deployment', 'queue'], ['Service', 'web']],
            array_map(fn (array $m) => [$m['kind'], $m['metadata']['name']], $workloads),
        );
        $this->assertSame(['sh', '-c', 'exec node queue.js'], $workloads[1]['spec']['template']['spec']['containers'][0]['command']);
    }

    public function test_a_process_of_a_tini_image_passes_only_its_arguments_so_tini_stays_pid_1(): void
    {
        $manifests = $this->manifests(type: ApplicationType::NODE, processes: [self::process(['command' => 'node worker.js']), self::scheduler(['command' => 'node cron.js'])]);

        $container = $manifests->workloads($this->deployment())[1]['spec']['template']['spec']['containers'][0];
        $cron = $manifests->cronJobs($this->deployment())[0]['spec']['jobTemplate']['spec']['template']['spec']['containers'][0];

        $this->assertSame([['sh', '-c', 'exec node worker.js'], false], [$container['args'], array_key_exists('command', $container)]);
        $this->assertSame([['sh', '-c', 'exec node cron.js'], false], [$cron['args'], array_key_exists('command', $cron)]);
    }

    public function test_the_probe_sends_the_first_domain_of_the_environment_as_the_host(): void
    {
        $later = new Domain(['hostname' => 'www.example.com']);
        $later->id = 9;
        $first = new Domain(['hostname' => 'shop.example.com']);
        $first->id = 4;

        $web = $this->manifests(domains: [$later, $first])->web($this->deployment());

        $this->assertSame(
            ['path' => '/up', 'port' => 'http', 'httpHeaders' => [['name' => 'Host', 'value' => 'shop.example.com']]],
            $web['spec']['template']['spec']['containers'][0]['readinessProbe']['httpGet'],
        );
    }

    public function test_a_single_command_replaces_the_shell_and_a_shell_construct_runs_as_it_is(): void
    {
        $this->assertSame([
            ['sh', '-c', 'exec php artisan queue:work --queue="high,default" --tries=3'],
            ['sh', '-c', 'exec node server.js > /dev/stdout'],
            ['sh', '-c', 'exec php artisan horizon'],
            ['sh', '-c', 'php artisan migrate --force && php artisan horizon'],
            ['sh', '-c', 'php artisan inertia:start-ssr 2>&1 | tee ssr.log'],
            ['sh', '-c', 'QUEUE=high php artisan queue:work'],
            ['sh', '-c', 'node $(cat entry.txt)'],
        ], array_map(EnvironmentManifests::shellCommand(...), [
            'php artisan queue:work --queue="high,default" --tries=3',
            'node server.js > /dev/stdout',
            'exec php artisan horizon',
            'php artisan migrate --force && php artisan horizon',
            'php artisan inertia:start-ssr 2>&1 | tee ssr.log',
            'QUEUE=high php artisan queue:work',
            'node $(cat entry.txt)',
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<Process>  $processes
     * @param  list<Domain>  $domains
     */
    private function manifests(array $attributes = [], ApplicationType $type = ApplicationType::LARAVEL, int $port = 8080, ?Cluster $cluster = null, array $processes = [], array $domains = []): EnvironmentManifests
    {
        $application = new Application(['type' => $type, 'port' => $port]);
        $application->id = 3;
        $application->setRelation('cluster', $cluster ?? new Cluster(['kind' => ClusterKind::LOCAL]));

        $environment = new Environment(['name' => 'production', ...$attributes]);
        $environment->id = 7;
        $environment->application_id = 3;
        $environment->setRelation('application', $application);
        $environment->setRelation('processes', new Collection($processes));
        $environment->setRelation('domains', new Collection($domains));

        return new EnvironmentManifests($environment);
    }

    /** The password is encrypted, and this test runs without the app, so the cluster has none. */
    private function clusterWithRegistry(): Cluster
    {
        return new Cluster([
            'kind' => ClusterKind::CONNECTED,
            'registry_url' => '192.0.2.5:5000/team',
            'registry_username' => 'robot',
            'registry_insecure' => true,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private static function process(array $attributes = []): Process
    {
        return new Process([
            'name' => 'worker',
            'kind' => ProcessKind::LONG_RUNNING,
            'command' => 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600',
            'size' => Size::S,
            'min_instances' => 1,
            'max_instances' => 1,
            'cpu_target' => 70,
            ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private static function scheduler(array $attributes = []): Process
    {
        return new Process(['name' => 'scheduler', 'kind' => ProcessKind::SCHEDULED, 'command' => 'php artisan schedule:run', 'schedule' => '* * * * *', 'size' => Size::S, ...$attributes]);
    }

    private function deployment(): Deployment
    {
        $deployment = new Deployment(['image' => 'registry.opencloud.svc.cluster.local:5000/3/7:abc1234-12']);
        $deployment->id = 12;

        return $deployment;
    }
}
