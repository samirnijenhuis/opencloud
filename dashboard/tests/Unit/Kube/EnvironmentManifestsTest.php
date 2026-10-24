<?php

namespace Tests\Unit\Kube;

use App\Clusters\ClusterRegistry;
use App\Enums\ApplicationType;
use App\Enums\ClusterKind;
use App\Enums\Size;
use App\Kube\Manifests\EnvironmentManifests;
use App\Models\Application;
use App\Models\Cluster;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Process;
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

    public function test_a_process_runs_its_command_in_a_shell_with_its_instances(): void
    {
        $labels = ['opencloud.dev/application' => '3', 'opencloud.dev/environment' => '7', 'opencloud.dev/process' => 'reverb'];
        $reverb = new Process(['name' => 'reverb', 'command' => 'php artisan reverb:start --host=0.0.0.0 --port=8080', 'instances' => 3, 'exclusive' => false]);

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
                        'resources' => self::RESOURCES,
                    ]]],
                ],
            ],
        ], $this->manifests()->process($reverb, $this->deployment()));
    }

    public function test_an_exclusive_process_runs_exactly_one_pod_and_recreates_it(): void
    {
        $labels = ['opencloud.dev/application' => '3', 'opencloud.dev/environment' => '7', 'opencloud.dev/process' => 'scheduler'];
        $scheduler = new Process(['name' => 'scheduler', 'command' => 'php artisan schedule:work', 'instances' => 1, 'exclusive' => true]);

        $this->assertSame([
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => ['name' => 'scheduler', 'namespace' => 'oc-env-7', 'labels' => $labels],
            'spec' => [
                'replicas' => 1,
                'selector' => ['matchLabels' => $labels],
                'strategy' => ['type' => 'Recreate'],
                'template' => [
                    'metadata' => ['labels' => [...$labels, 'opencloud.dev/deployment' => '12']],
                    'spec' => ['containers' => [[
                        'name' => 'scheduler',
                        'image' => 'registry.opencloud.svc.cluster.local:5000/3/7:abc1234-12',
                        'command' => ['sh', '-c', 'exec php artisan schedule:work'],
                        'envFrom' => [['secretRef' => ['name' => 'env-12']]],
                        'resources' => self::RESOURCES,
                    ]]],
                ],
            ],
        ], $this->manifests(['min_instances' => 3, 'max_instances' => 6])->process($scheduler, $this->deployment()));
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
        $this->assertSame([
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
        ], $this->manifests(['min_instances' => 2, 'max_instances' => 5, 'cpu_target' => 60])->autoscaler());
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
        $this->assertSame([['name' => 'opencloud-registry']], $manifests->process(self::worker(), $deployment)['spec']['template']['spec']['imagePullSecrets']);
        $this->assertSame(['containers'], array_keys($this->manifests()->web($deployment)['spec']['template']['spec']), 'The local cluster pulls without a secret.');
    }

    public function test_each_process_gets_a_deployment_and_the_selector_finds_the_removed_ones(): void
    {
        $scheduler = new Process(['name' => 'scheduler', 'command' => 'php artisan schedule:work', 'instances' => 1, 'exclusive' => true]);
        $manifests = $this->manifests(processes: [self::worker(), $scheduler]);

        $this->assertSame(
            [['Deployment', 'web'], ['Deployment', 'worker'], ['Deployment', 'scheduler'], ['Service', 'web']],
            array_map(fn (array $m) => [$m['kind'], $m['metadata']['name']], $manifests->workloads($this->deployment())),
        );
        $this->assertSame([['horizontalpodautoscaler', 'web']], $manifests->absent());
        $this->assertSame(
            'opencloud.dev/environment=7,opencloud.dev/process,opencloud.dev/process notin (web,worker,scheduler)',
            $manifests->removedProcessesSelector(),
        );
    }

    public function test_without_processes_only_web_runs_and_an_autoscaled_web_gets_an_autoscaler(): void
    {
        $manifests = $this->manifests(['max_instances' => 3]);

        $this->assertSame(
            [['Deployment', 'web'], ['Service', 'web'], ['HorizontalPodAutoscaler', 'web']],
            array_map(fn (array $m) => [$m['kind'], $m['metadata']['name']], $manifests->workloads($this->deployment())),
        );
        $this->assertSame([], $manifests->absent());
        $this->assertSame('opencloud.dev/environment=7,opencloud.dev/process,opencloud.dev/process notin (web)', $manifests->removedProcessesSelector());
    }

    public function test_a_dockerfile_app_runs_its_own_processes(): void
    {
        $queue = new Process(['name' => 'queue', 'command' => 'node queue.js', 'instances' => 2, 'exclusive' => false]);
        $manifests = $this->manifests(type: ApplicationType::Dockerfile, processes: [$queue]);

        $workloads = $manifests->workloads($this->deployment());

        $this->assertSame(
            [['Deployment', 'web'], ['Deployment', 'queue'], ['Service', 'web']],
            array_map(fn (array $m) => [$m['kind'], $m['metadata']['name']], $workloads),
        );
        $this->assertSame(['sh', '-c', 'exec node queue.js'], $workloads[1]['spec']['template']['spec']['containers'][0]['command']);
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
     */
    private function manifests(array $attributes = [], ApplicationType $type = ApplicationType::Laravel, int $port = 8080, ?Cluster $cluster = null, array $processes = []): EnvironmentManifests
    {
        $application = new Application(['type' => $type, 'port' => $port]);
        $application->id = 3;
        $application->setRelation('cluster', $cluster ?? new Cluster(['kind' => ClusterKind::Local]));

        $environment = new Environment(['name' => 'production', ...$attributes]);
        $environment->id = 7;
        $environment->application_id = 3;
        $environment->setRelation('application', $application);
        $environment->setRelation('processes', new Collection($processes));

        return new EnvironmentManifests($environment);
    }

    /** The password is encrypted, and this test runs without the app, so the cluster has none. */
    private function clusterWithRegistry(): Cluster
    {
        return new Cluster([
            'kind' => ClusterKind::Connected,
            'registry_url' => '192.0.2.5:5000/team',
            'registry_username' => 'robot',
            'registry_insecure' => true,
        ]);
    }

    private static function worker(): Process
    {
        return new Process(['name' => 'worker', 'command' => 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600', 'instances' => 1, 'exclusive' => false]);
    }

    private function deployment(): Deployment
    {
        $deployment = new Deployment(['image' => 'registry.opencloud.svc.cluster.local:5000/3/7:abc1234-12']);
        $deployment->id = 12;

        return $deployment;
    }
}
