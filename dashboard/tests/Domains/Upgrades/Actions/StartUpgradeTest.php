<?php

namespace Tests\Domains\Upgrades\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Members\Models\User;
use App\Domains\Upgrades\Actions\StartUpgrade;
use App\Support\Kube\FakeKubectl;
use App\Support\Kube\KubectlResult;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StartUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private FakeKubectl $kubectl;

    private Filesystem $data;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'UTC'));
        config(['opencloud.version' => 'v0.5.3']);
        $this->data = Storage::fake('opencloud-data');
        config(['opencloud.data_path' => $this->data->path('')]);
        Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'name' => 'local']);
        $this->kubectl = FakeKubectl::install();
        $this->kubectl->respond('get deployment dashboard', new KubectlResult(0, json_encode(['spec' => ['template' => ['spec' => ['containers' => [
            ['name' => 'web', 'image' => 'ghcr.io/opencloud-dev/opencloud:v0.5.3'],
            ['name' => 'worker', 'image' => 'ghcr.io/opencloud-dev/opencloud:v0.5.3'],
        ]]]]])));
    }

    public function test_it_applies_the_upgrade_job_with_the_dashboard_image_and_marks_the_upgrade_as_starting(): void
    {
        $owner = User::factory()->owner()->create();

        app(StartUpgrade::class)->execute($owner, 'v0.6.0');

        $job = $this->kubectl->appliedManifests()[0];
        $this->assertSame('opencloud-upgrade-1795168800', $job['metadata']['name']);
        $this->assertSame('ghcr.io/opencloud-dev/opencloud:v0.5.3', $job['spec']['template']['spec']['containers'][0]['image']);
        $this->assertSame(['state' => 'starting', 'from' => 'v0.5.3', 'target' => 'v0.6.0', 'message' => ''], json_decode($this->data->get('upgrade.json'), true));
        $event = AuditEvent::sole();
        $this->assertSame('opencloud.upgrade_started', $event->action);
        $this->assertSame(['from' => 'v0.5.3', 'target' => 'v0.6.0'], $event->context);
        $this->assertTrue($event->user->is($owner));
    }

    public function test_it_refuses_while_a_deployment_builds_because_the_restart_would_stop_the_build(): void
    {
        Deployment::factory()->create(['status' => DeploymentStatus::BUILDING]);

        try {
            app(StartUpgrade::class)->execute(User::factory()->owner()->create(), 'v0.6.0');
            $this->fail('The upgrade started during a build.');
        } catch (ValidationException $e) {
            $this->assertSame(['target' => ['A deployment runs now. Wait until it ends, then update.']], $e->errors());
        }

        $this->assertSame([], $this->kubectl->appliedManifests());
        $this->assertFalse($this->data->exists('upgrade.json'));
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_it_refuses_while_the_host_runs_an_upgrade_and_keeps_its_state(): void
    {
        $running = '{"state":"running","from":"v0.5.3","target":"v0.6.0","message":"Opencloud downloads and installs v0.6.0."}';
        $this->data->put('upgrade.json', $running);

        try {
            app(StartUpgrade::class)->execute(User::factory()->owner()->create(), 'v0.6.0');
            $this->fail('A second upgrade started.');
        } catch (ValidationException $e) {
            $this->assertSame(['target' => ['An update runs now. If it stopped, run "sudo opencloud upgrade" on the server.']], $e->errors());
        }

        $this->assertSame([], $this->kubectl->appliedManifests());
        $this->assertSame($running, $this->data->get('upgrade.json'));
    }

    public function test_a_start_that_systemd_never_ran_does_not_block_the_next_start(): void
    {
        $this->data->put('upgrade.json', '{"state":"starting","from":"v0.5.3","target":"v0.6.0","message":""}');

        app(StartUpgrade::class)->execute(User::factory()->owner()->create(), 'v0.6.0');

        $this->assertSame(['Job'], array_column($this->kubectl->appliedManifests(), 'kind'));
    }
}
