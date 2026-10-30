<?php

namespace Tests\Domains\Clusters;

use App\Domains\Applications\Enums\ApplicationType;
use App\Domains\Applications\Models\Application;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Jobs\RunDeployment;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Models\Environment;
use App\Support\Kube\FakeKubectl;
use App\Support\Kube\KubectlResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeProcesses;
use Tests\TestCase;

class ImageCopyTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private FakeProcesses $processes;

    private FakeKubectl $kubectl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processes = FakeProcesses::install()->withLaravelRepository();
        $this->kubectl = FakeKubectl::install();
        $this->kubectl->respond('exec deploy/web', new KubectlResult(0, 'HTTP/1.1 200 OK'));
    }

    public function test_a_deployment_on_a_connected_cluster_copies_the_image_and_pulls_it_with_the_pull_secret(): void
    {
        $deployment = $this->deployment(insecure: false);
        $environment = $deployment->environment;
        $target = "registry.example.com/team/{$environment->application_id}/{$environment->id}:0123456-{$deployment->id}";

        RunDeployment::dispatchSync($deployment);

        $this->assertSame(DeploymentStatus::LIVE, $deployment->fresh()->status);
        $this->assertSame($target, $deployment->fresh()->image);
        $this->assertSame(
            [['crane', 'copy', "10.43.200.200:5000/{$environment->application_id}/{$environment->id}:0123456-{$deployment->id}", $target]],
            $this->craneCommands(),
        );
        $this->assertSame(
            ['{"auths":{"registry.example.com":{"username":"robot","password":"REGISTRY_PASSWORD","auth":"cm9ib3Q6UkVHSVNUUllfUEFTU1dPUkQ="}}}'],
            $this->processes->craneConfigs,
        );

        $applied = $this->kubectl->appliedManifests();
        $this->assertSame(
            [['Namespace', 'oc-env-'.$environment->id], ['Secret', 'opencloud-registry'], ['Namespace', 'oc-env-'.$environment->id], ['NetworkPolicy', 'ingress-from-namespace-and-caddy'], ['NetworkPolicy', 'egress-to-pods-and-internet'], ['Secret', "env-{$deployment->id}"], ['Deployment', 'web'], ['Service', 'web']],
            array_map(fn (array $manifest) => [$manifest['kind'], $manifest['metadata']['name']], $applied),
        );
        $this->assertSame(
            '{"auths":{"registry.example.com":{"username":"robot","password":"REGISTRY_PASSWORD","auth":"cm9ib3Q6UkVHSVNUUllfUEFTU1dPUkQ="}}}',
            base64_decode($applied[1]['data']['.dockerconfigjson']),
        );
        $this->assertSame([['name' => 'opencloud-registry']], $applied[6]['spec']['template']['spec']['imagePullSecrets']);
        $this->assertSame($target, $applied[6]['spec']['template']['spec']['containers'][0]['image']);
        $this->assertStringContainsString("Copying the image to {$target}.", $deployment->fresh()->log);
    }

    public function test_an_insecure_registry_gets_the_insecure_flag(): void
    {
        $deployment = $this->deployment(insecure: true);
        $environment = $deployment->environment;

        RunDeployment::dispatchSync($deployment);

        $this->assertSame(
            [['crane', 'copy', '--insecure', "10.43.200.200:5000/{$environment->application_id}/{$environment->id}:0123456-{$deployment->id}", "registry.example.com/team/{$environment->application_id}/{$environment->id}:0123456-{$deployment->id}"]],
            $this->craneCommands(),
        );
    }

    public function test_a_failed_copy_fails_the_deployment_before_anything_changes_on_the_cluster(): void
    {
        $this->processes->craneExitCode = 1;
        $deployment = $this->deployment(insecure: false);

        RunDeployment::dispatchSync($deployment);

        $this->assertSame(DeploymentStatus::FAILED, $deployment->fresh()->status);
        $this->assertSame([], $this->kubectl->calls);
        $this->assertStringContainsString('crane could not copy the image to the cluster registry. UNAUTHORIZED: authentication required', $deployment->fresh()->log);
    }

    /** @return list<list<string>> */
    private function craneCommands(): array
    {
        return array_values(array_filter($this->processes->commands(), fn (array $command) => $command[0] === 'crane'));
    }

    private function deployment(bool $insecure): Deployment
    {
        $cluster = Cluster::factory()->create([
            'kind' => ClusterKind::CONNECTED,
            'registry_url' => 'registry.example.com/team',
            'registry_username' => 'robot',
            'registry_password' => 'REGISTRY_PASSWORD',
            'registry_insecure' => $insecure,
        ]);
        $application = Application::factory()->for($cluster)->create(['type' => ApplicationType::LARAVEL]);
        $environment = Environment::factory()->for($application)->create([
            'deploy_commands' => [], 'min_instances' => 1, 'max_instances' => 1,
        ]);

        return Deployment::factory()->for($environment)->create(['commit_sha' => self::SHA]);
    }
}
