<?php

namespace Tests\Feature\Clusters;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Clusters\Actions\ConnectCluster;
use App\Domains\Clusters\Actions\DisconnectCluster;
use App\Domains\Clusters\Actions\ReinstallClusterParts;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Enums\ClusterStatus;
use App\Domains\Clusters\Jobs\InstallClusterParts;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Members\Models\User;
use App\Domains\Routing\DnsResolver;
use App\Domains\Routing\Jobs\SyncCaddy;
use App\Support\Kube\FakeKubectl;
use App\Support\Kube\KubectlResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConnectClusterTest extends TestCase
{
    use RefreshDatabase;

    private const KUBECONFIG = "apiVersion: v1\nkind: Config\nusers:\n- name: admin\n  user:\n    token: KUBE_TOKEN_SECRET\n";

    private FakeKubectl $kubectl;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->kubectl = FakeKubectl::install();
    }

    public function test_connecting_checks_the_kubeconfig_stores_the_secrets_encrypted_and_starts_the_install(): void
    {
        $this->kubectl->respond('auth can-i', new KubectlResult(0, "yes\n"));

        $cluster = app(ConnectCluster::class)->handle($owner = User::factory()->owner()->create(), $this->data());

        $this->assertSame([
            ['cluster' => 'fra1', 'args' => ['version', '--request-timeout=15s'], 'stdin' => null],
            ['cluster' => 'fra1', 'args' => ['auth', 'can-i', '*', '*', '--all-namespaces', '--request-timeout=15s'], 'stdin' => null],
        ], $this->kubectl->calls);

        $cluster->refresh();
        $this->assertSame(ClusterKind::Connected, $cluster->kind);
        $this->assertSame(ClusterStatus::Installing, $cluster->status);
        $this->assertSame(self::KUBECONFIG, $cluster->kubeconfig);
        $this->assertSame(['192.0.2.5:5000', 'robot', 'REGISTRY_PASSWORD_SECRET', true], [
            $cluster->registry()->address, $cluster->registry()->username, $cluster->registry()->password, $cluster->registry()->insecure,
        ]);

        $raw = (array) DB::table('clusters')->where('id', $cluster->id)->first();
        $this->assertStringNotContainsString('KUBE_TOKEN_SECRET', $raw['kubeconfig']);
        $this->assertStringNotContainsString('REGISTRY_PASSWORD_SECRET', $raw['registry_password']);

        $this->assertSame([$cluster->id], Queue::pushed(InstallClusterParts::class)->map(fn (InstallClusterParts $job) => $job->cluster->id)->all());
        $event = AuditEvent::query()->sole();
        $this->assertSame(['cluster.connected', $owner->id, ['name' => 'fra1', 'registry' => '192.0.2.5:5000']], [$event->action, $event->user_id, $event->context]);
    }

    public function test_a_kubeconfig_that_cannot_reach_the_cluster_is_refused_with_the_kubectl_error(): void
    {
        $this->kubectl->respond('version', new KubectlResult(1, "Client Version: v1.36.5\n", "Unable to connect to the server: dial tcp 192.0.2.9:6443: i/o timeout\nmore detail\n"));

        $this->assertRefused(
            'Opencloud cannot reach the cluster with this kubeconfig. kubectl said: Unable to connect to the server: dial tcp 192.0.2.9:6443: i/o timeout',
        );
        $this->assertCount(1, $this->kubectl->calls);
    }

    public function test_a_kubeconfig_that_kubectl_cannot_read_is_refused_without_the_temporary_file_name(): void
    {
        $this->kubectl->respond('version', new KubectlResult(1, '', 'error: error loading config file "/tmp/kubeconfig-a1B2c3": yaml: mapping values are not allowed in this context'));

        $this->assertRefused(
            'Opencloud cannot reach the cluster with this kubeconfig. kubectl said: error: error loading config file the kubeconfig: yaml: mapping values are not allowed in this context',
        );
    }

    public function test_a_kubeconfig_without_full_rights_is_refused(): void
    {
        $this->kubectl->respond('auth can-i', new KubectlResult(1, "no\n"));

        $this->assertRefused('This kubeconfig does not have full rights on the cluster. Opencloud needs a kubeconfig with the cluster-admin role.');
    }

    public function test_the_install_applies_the_parts_and_stores_the_load_balancer_ip_when_caddy_runs(): void
    {
        config(['opencloud.version' => '1.4.0', 'opencloud.caddy_image' => 'caddy:2.11.7']);
        $cluster = $this->cluster(['status' => ClusterStatus::Installing, 'error' => 'an old error']);
        $this->caddyIs(available: 1);
        $this->kubectl->respond('get service caddy', $this->service([['ip' => '2001:db8::5'], ['ip' => '203.0.113.20']]));

        $job = (new InstallClusterParts($cluster))->withFakeQueueInteractions();
        $job->handle(app(DnsResolver::class));

        $this->assertSame(
            [['Namespace', 'opencloud'], ['Namespace', 'oc-datastores'], ['PersistentVolumeClaim', 'caddy'], ['Deployment', 'caddy'], ['Service', 'caddy']],
            array_map(fn (array $manifest) => [$manifest['kind'], $manifest['metadata']['name']], $this->kubectl->appliedManifests()),
        );
        $cluster->refresh();
        $this->assertSame([ClusterStatus::Ready, '203.0.113.20', '1.4.0', null], [$cluster->status, $cluster->ingress_ip, $cluster->parts_version, $cluster->error]);
        $this->assertSame([$cluster->id], Queue::pushed(SyncCaddy::class)->map(fn (SyncCaddy $sync) => $sync->cluster->id)->all());
        $job->assertNotReleased();
    }

    public function test_the_install_resolves_a_load_balancer_host_name_to_its_ip(): void
    {
        $cluster = $this->cluster(['status' => ClusterStatus::Installing]);
        $this->caddyIs(available: 1);
        $this->kubectl->respond('get service caddy', $this->service([['hostname' => 'abc.elb.example.com']]));
        $this->app->instance(DnsResolver::class, new class extends DnsResolver
        {
            public function ipv4(string $hostname): array
            {
                return $hostname === 'abc.elb.example.com' ? ['198.51.100.7', '198.51.100.8'] : [];
            }
        });

        (new InstallClusterParts($cluster))->withFakeQueueInteractions()->handle(app(DnsResolver::class));

        $this->assertSame('198.51.100.7', $cluster->fresh()->ingress_ip);
    }

    public function test_the_install_tries_again_while_the_load_balancer_has_no_address(): void
    {
        $cluster = $this->cluster(['status' => ClusterStatus::Installing]);
        $this->caddyIs(available: 1);
        $this->kubectl->respond('get service caddy', $this->service([]));

        $job = (new InstallClusterParts($cluster))->withFakeQueueInteractions();
        $job->handle(app(DnsResolver::class));

        $job->assertReleased(delay: 15);
        $this->assertSame([ClusterStatus::Installing, null], [$cluster->fresh()->status, $cluster->fresh()->ingress_ip]);
        Queue::assertNotPushed(SyncCaddy::class);
    }

    public function test_the_install_tries_again_while_caddy_does_not_run_and_fails_after_the_last_try(): void
    {
        $cluster = $this->cluster(['status' => ClusterStatus::Installing]);
        $this->caddyIs(available: 0);
        $this->kubectl->respond('get service caddy', $this->service([['ip' => '203.0.113.20']]));

        $job = (new InstallClusterParts($cluster))->withFakeQueueInteractions();
        $job->handle(app(DnsResolver::class));
        $job->assertReleased(delay: 15);

        $last = (new InstallClusterParts($cluster))->withFakeQueueInteractions();
        $last->job->attempts = InstallClusterParts::MAX_TRIES;
        $last->handle(app(DnsResolver::class));

        $last->assertNotReleased();
        $cluster->refresh();
        $this->assertSame(ClusterStatus::Failed, $cluster->status);
        $this->assertSame('Caddy did not start in 15 minutes. Read its events with: kubectl -n opencloud describe pod -l app.kubernetes.io/name=caddy', $cluster->error);
    }

    public function test_a_failed_apply_fails_the_install_and_keeps_the_error(): void
    {
        $cluster = $this->cluster(['status' => ClusterStatus::Installing]);
        $this->kubectl->respond('apply', new KubectlResult(1, '', 'error: namespaces "opencloud" is forbidden'));

        $job = (new InstallClusterParts($cluster))->withFakeQueueInteractions();
        $job->handle(app(DnsResolver::class));

        $job->assertNotReleased();
        $this->assertSame([ClusterStatus::Failed, 'error: namespaces "opencloud" is forbidden'], [$cluster->fresh()->status, $cluster->fresh()->error]);
    }

    public function test_reinstalling_clears_the_error_and_runs_the_install_again(): void
    {
        $cluster = $this->cluster(['status' => ClusterStatus::Failed, 'error' => 'timeout']);

        app(ReinstallClusterParts::class)->handle($cluster, User::factory()->owner()->create());

        $this->assertSame([ClusterStatus::Installing, null], [$cluster->fresh()->status, $cluster->fresh()->error]);
        $this->assertSame([$cluster->id], Queue::pushed(InstallClusterParts::class)->map(fn (InstallClusterParts $job) => $job->cluster->id)->all());
        $this->assertSame('cluster.parts_reinstalled', AuditEvent::query()->sole()->action);
    }

    public function test_the_parts_of_the_local_cluster_are_never_reinstalled_or_disconnected(): void
    {
        $local = $this->cluster(['kind' => ClusterKind::Local, 'name' => 'local']);
        $owner = User::factory()->owner()->create();

        $this->assertSame(
            ['cluster' => ['The installer manages the parts of the local cluster. Run opencloud upgrade on the server.']],
            $this->errors(fn () => app(ReinstallClusterParts::class)->handle($local, $owner)),
        );
        $this->assertSame(
            ['cluster' => ['The local cluster runs the dashboard, so it cannot be disconnected.']],
            $this->errors(fn () => app(DisconnectCluster::class)->handle($local, $owner, removeParts: true)),
        );
        Queue::assertNothingPushed();
        $this->assertSame([], $this->kubectl->calls);
        $this->assertNotNull($local->fresh());
    }

    public function test_disconnecting_is_refused_while_applications_or_datastores_use_the_cluster(): void
    {
        $cluster = $this->cluster();
        Application::factory()->for($cluster)->count(2)->create();
        Datastore::factory()->for($cluster)->create();

        $this->assertSame(
            ['cluster' => ['This cluster still runs 2 applications and 1 datastores. Remove them first.']],
            $this->errors(fn () => app(DisconnectCluster::class)->handle($cluster, User::factory()->owner()->create(), removeParts: true)),
        );
        $this->assertSame([], $this->kubectl->calls);
        $this->assertNotNull($cluster->fresh());
    }

    public function test_disconnecting_with_removal_deletes_only_the_opencloud_namespaces(): void
    {
        $cluster = $this->cluster();

        app(DisconnectCluster::class)->handle($cluster, User::factory()->owner()->create(), removeParts: true);

        $this->assertSame([
            ['delete', 'namespace', 'opencloud', '--ignore-not-found', '--wait=false'],
            ['delete', 'namespace', 'oc-datastores', '--ignore-not-found', '--wait=false'],
        ], array_column($this->kubectl->calls, 'args'));
        $this->assertNull($cluster->fresh());
        $this->assertSame(['name' => 'fra1', 'removed_parts' => true], AuditEvent::query()->sole()->context);
    }

    public function test_disconnecting_without_removal_leaves_the_cluster_alone(): void
    {
        $cluster = $this->cluster();

        app(DisconnectCluster::class)->handle($cluster, User::factory()->owner()->create(), removeParts: false);

        $this->assertSame([], $this->kubectl->calls);
        $this->assertNull($cluster->fresh());
    }

    public function test_a_failed_removal_keeps_the_cluster(): void
    {
        $cluster = $this->cluster();
        $this->kubectl->respond('delete namespace', new KubectlResult(1, '', 'Unable to connect to the server'));

        $this->assertSame(
            ['cluster' => ['Opencloud could not remove its namespaces. Unable to connect to the server Try again, or disconnect without removing them.']],
            $this->errors(fn () => app(DisconnectCluster::class)->handle($cluster, User::factory()->owner()->create(), removeParts: true)),
        );
        $this->assertNotNull($cluster->fresh());
    }

    private function assertRefused(string $message): void
    {
        $this->assertSame(
            ['kubeconfig' => [$message]],
            $this->errors(fn () => app(ConnectCluster::class)->handle(User::factory()->owner()->create(), $this->data())),
        );
        $this->assertSame(0, Cluster::query()->count());
        Queue::assertNothingPushed();
    }

    /** @return array<string, list<string>> */
    private function errors(callable $action): array
    {
        try {
            $action();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('The action did not refuse.');
    }

    /** @return array{name: string, kubeconfig: string, registry_url: string, registry_username: string, registry_password: string, registry_insecure: bool} */
    private function data(): array
    {
        return [
            'name' => 'fra1',
            'kubeconfig' => self::KUBECONFIG,
            'registry_url' => '192.0.2.5:5000',
            'registry_username' => 'robot',
            'registry_password' => 'REGISTRY_PASSWORD_SECRET',
            'registry_insecure' => true,
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    private function cluster(array $attributes = []): Cluster
    {
        return Cluster::factory()->create(['name' => 'fra1', 'registry_url' => '192.0.2.5:5000', 'registry_username' => 'robot', 'registry_password' => 'x', ...$attributes]);
    }

    private function caddyIs(int $available): void
    {
        $this->kubectl->respond('get deployment caddy', new KubectlResult(0, json_encode(['kind' => 'Deployment', 'status' => ['availableReplicas' => $available]])));
    }

    /** @param  list<array<string, string>>  $ingress */
    private function service(array $ingress): KubectlResult
    {
        return new KubectlResult(0, json_encode(['kind' => 'Service', 'status' => ['loadBalancer' => $ingress === [] ? [] : ['ingress' => $ingress]]]));
    }
}
