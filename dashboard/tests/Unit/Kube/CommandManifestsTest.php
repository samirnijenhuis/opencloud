<?php

namespace Tests\Unit\Kube;

use App\Domains\Applications\Models\Application;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\CommandManifests;
use App\Domains\Environments\Enums\Size;
use App\Domains\Environments\Models\CommandRun;
use App\Domains\Environments\Models\Environment;
use PHPUnit\Framework\TestCase;

class CommandManifestsTest extends TestCase
{
    public function test_the_job_runs_the_command_once_with_the_image_and_env_of_the_live_deployment(): void
    {
        $labels = [
            'opencloud.dev/application' => '3',
            'opencloud.dev/environment' => '7',
            'opencloud.dev/process' => 'command',
            'opencloud.dev/deployment' => '12',
        ];

        $this->assertSame([
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => ['name' => 'cmd-41', 'namespace' => 'oc-env-7', 'labels' => $labels],
            'spec' => [
                'backoffLimit' => 0,
                'activeDeadlineSeconds' => 900,
                'ttlSecondsAfterFinished' => 3600,
                'template' => [
                    'metadata' => ['labels' => $labels],
                    'spec' => [
                        'restartPolicy' => 'Never',
                        'containers' => [[
                            'name' => 'command',
                            'image' => 'registry.opencloud.svc.cluster.local:5000/3/7:abc1234-12',
                            'command' => ['sh', '-c', 'php artisan migrate:status && echo "done"'],
                            'envFrom' => [['secretRef' => ['name' => 'env-12']]],
                            'resources' => [
                                'requests' => ['cpu' => '1000m', 'memory' => '1024Mi'],
                                'limits' => ['cpu' => '1000m', 'memory' => '1024Mi'],
                            ],
                        ]],
                    ],
                ],
            ],
        ], (new CommandManifests($this->commandRun(new Cluster(['kind' => ClusterKind::Local]))))->job());
    }

    public function test_a_cluster_with_its_own_registry_pulls_with_the_pull_secret(): void
    {
        $cluster = new Cluster(['kind' => ClusterKind::Connected, 'registry_url' => '192.0.2.5:5000/team', 'registry_username' => 'robot']);

        $spec = (new CommandManifests($this->commandRun($cluster)))->job()['spec']['template']['spec'];

        $this->assertSame([['name' => 'opencloud-registry']], $spec['imagePullSecrets']);
    }

    private function commandRun(Cluster $cluster): CommandRun
    {
        $application = new Application;
        $application->id = 3;
        $application->setRelation('cluster', $cluster);

        $environment = new Environment(['name' => 'production', 'size' => Size::M]);
        $environment->id = 7;
        $environment->application_id = 3;
        $environment->setRelation('application', $application);

        $deployment = new Deployment(['image' => 'registry.opencloud.svc.cluster.local:5000/3/7:abc1234-12']);
        $deployment->id = 12;

        $run = new CommandRun(['command' => 'php artisan migrate:status && echo "done"']);
        $run->id = 41;
        $run->setRelation('environment', $environment);
        $run->setRelation('deployment', $deployment);

        return $run;
    }
}
