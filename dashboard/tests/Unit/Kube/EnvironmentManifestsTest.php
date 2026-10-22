<?php

namespace Tests\Unit\Kube;

use App\Enums\ApplicationType;
use App\Enums\Size;
use App\Kube\Manifests\EnvironmentManifests;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Environment;
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
            'envFrom' => [['secretRef' => ['name' => 'env-12']]],
            'resources' => [
                'requests' => ['cpu' => '1000m', 'memory' => '1024Mi'],
                'limits' => ['cpu' => '1000m', 'memory' => '1024Mi'],
            ],
        ], $web['spec']['template']['spec']['containers'][0]);
    }

    public function test_the_worker_runs_the_queue(): void
    {
        $labels = ['opencloud.dev/application' => '3', 'opencloud.dev/environment' => '7', 'opencloud.dev/process' => 'worker'];

        $this->assertSame([
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => ['name' => 'worker', 'namespace' => 'oc-env-7', 'labels' => $labels],
            'spec' => [
                'replicas' => 1,
                'selector' => ['matchLabels' => $labels],
                'strategy' => ['type' => 'RollingUpdate', 'rollingUpdate' => ['maxUnavailable' => 0, 'maxSurge' => 1]],
                'template' => [
                    'metadata' => ['labels' => [...$labels, 'opencloud.dev/deployment' => '12']],
                    'spec' => ['containers' => [[
                        'name' => 'worker',
                        'image' => 'registry.opencloud.svc.cluster.local:5000/3/7:abc1234-12',
                        'command' => ['php', 'artisan', 'queue:work', '--sleep=3', '--tries=3', '--max-time=3600'],
                        'envFrom' => [['secretRef' => ['name' => 'env-12']]],
                        'resources' => self::RESOURCES,
                    ]]],
                ],
            ],
        ], $this->manifests()->worker($this->deployment()));
    }

    public function test_the_scheduler_runs_exactly_one_pod_and_recreates_it(): void
    {
        $labels = ['opencloud.dev/application' => '3', 'opencloud.dev/environment' => '7', 'opencloud.dev/process' => 'scheduler'];

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
                        'command' => ['php', 'artisan', 'schedule:work'],
                        'envFrom' => [['secretRef' => ['name' => 'env-12']]],
                        'resources' => self::RESOURCES,
                    ]]],
                ],
            ],
        ], $this->manifests(['min_instances' => 3, 'max_instances' => 6])->scheduler($this->deployment()));
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

    public function test_a_laravel_app_runs_three_processes_and_removes_the_autoscaler_when_it_has_a_fixed_count(): void
    {
        $manifests = $this->manifests();

        $this->assertSame(
            [['Deployment', 'web'], ['Deployment', 'worker'], ['Deployment', 'scheduler'], ['Service', 'web']],
            array_map(fn (array $m) => [$m['kind'], $m['metadata']['name']], $manifests->workloads($this->deployment())),
        );
        $this->assertSame([['horizontalpodautoscaler', 'web']], $manifests->absent());
    }

    public function test_switched_off_processes_are_removed_and_an_autoscaled_web_gets_an_autoscaler(): void
    {
        $manifests = $this->manifests(['worker_enabled' => false, 'scheduler_enabled' => false, 'max_instances' => 3]);

        $this->assertSame(
            [['Deployment', 'web'], ['Service', 'web'], ['HorizontalPodAutoscaler', 'web']],
            array_map(fn (array $m) => [$m['kind'], $m['metadata']['name']], $manifests->workloads($this->deployment())),
        );
        $this->assertSame([['deployment', 'worker'], ['deployment', 'scheduler']], $manifests->absent());
    }

    public function test_a_dockerfile_app_runs_only_web(): void
    {
        $manifests = $this->manifests(type: ApplicationType::Dockerfile);

        $this->assertSame(
            [['Deployment', 'web'], ['Service', 'web']],
            array_map(fn (array $m) => [$m['kind'], $m['metadata']['name']], $manifests->workloads($this->deployment())),
        );
        $this->assertSame([['deployment', 'worker'], ['deployment', 'scheduler'], ['horizontalpodautoscaler', 'web']], $manifests->absent());
    }

    /** @param  array<string, mixed>  $attributes */
    private function manifests(array $attributes = [], ApplicationType $type = ApplicationType::Laravel, int $port = 8080): EnvironmentManifests
    {
        $application = new Application(['type' => $type, 'port' => $port]);
        $application->id = 3;

        $environment = new Environment(['name' => 'production', ...$attributes]);
        $environment->id = 7;
        $environment->application_id = 3;
        $environment->setRelation('application', $application);

        return new EnvironmentManifests($environment);
    }

    private function deployment(): Deployment
    {
        $deployment = new Deployment(['image' => 'registry.opencloud.svc.cluster.local:5000/3/7:abc1234-12']);
        $deployment->id = 12;

        return $deployment;
    }
}
