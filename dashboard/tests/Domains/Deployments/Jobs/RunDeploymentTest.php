<?php

namespace Tests\Domains\Deployments\Jobs;

use App\Domains\Applications\Enums\ApplicationType;
use App\Domains\Applications\Models\Application;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Deployments\DeploymentSteps;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Jobs\RunDeployment;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Enums\ProcessKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Environments\Models\Process;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Actions\SetPrimaryDomain;
use App\Domains\Routing\Enums\DnsStatus;
use App\Domains\Routing\Models\Domain;
use App\Support\Kube\FakeKubectl;
use App\Support\Kube\KubectlResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Tests\Fakes\FakeProcesses;
use Tests\TestCase;

class RunDeploymentTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private const APPLY = ['apply', '--server-side', '--force-conflicts', '--field-manager=opencloud', '-f', '-'];

    private const HOME_PAGE_CHECK = "\$context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]);"
        ."@file_get_contents('http://127.0.0.1:8080/', false, \$context);"
        ."echo \$http_response_header[0] ?? 'no response';";

    private FakeKubectl $kubectl;

    private Environment $environment;

    private Deployment $old;

    private Deployment $new;

    /** @var list<array{0: string, 1: string}> */
    private array $moves = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-23 12:00:00'));
        $processes = FakeProcesses::install()->withLaravelRepository();
        $processes->buildOutput = ['#9 DONE 3.1s'];
        $this->kubectl = FakeKubectl::install();

        $cluster = Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'name' => 'local']);
        $application = Application::factory()->for($cluster)->create(['repository' => 'acme/shop', 'type' => ApplicationType::LARAVEL]);
        $this->environment = Environment::factory()->for($application)->create(['deploy_commands' => ['php artisan migrate --force']]);
        Process::factory()->for($this->environment)->create(['command' => 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600']);
        Process::factory()->for($this->environment)->scheduled()->create();
        EnvVar::factory()->for($this->environment)->create(['key' => 'DB_PASSWORD', 'value' => 'hunter22', 'is_secret' => true]);
        $this->old = Deployment::factory()->for($this->environment)->create(['status' => DeploymentStatus::LIVE, 'image' => 'old-image']);
        $this->new = Deployment::factory()->for($this->environment)->create(['commit_sha' => self::SHA]);

        $this->kubectl->respond("logs job/deploy-{$this->new->id}", new KubectlResult(0, "Migrating: create_users_table\nconnected with hunter22\n"));
        $this->kubectl->respond('exec deploy/web', new KubectlResult(0, 'HTTP/1.1 200 OK'));
        $this->kubectl->respond('get secrets', $this->secretList(['env', "env-{$this->old->id}", "env-{$this->new->id}"]));

        Deployment::updated(function (Deployment $deployment) {
            if ($deployment->wasChanged('status')) {
                $this->moves[] = [$deployment->id === $this->new->id ? 'new' : 'old', $deployment->status->value];
            }
        });
    }

    public function test_a_deployment_builds_runs_the_deploy_commands_rolls_out_and_goes_live(): void
    {
        RunDeployment::dispatchSync($this->new);

        $namespace = "oc-env-{$this->environment->id}";
        $job = "job/deploy-{$this->new->id}";
        $image = "registry.opencloud.svc.cluster.local:5000/{$this->environment->application_id}/{$this->environment->id}:0123456-{$this->new->id}";

        $this->assertSame([['new', 'building'], ['new', 'deploying'], ['old', 'superseded'], ['new', 'live']], $this->moves);
        $this->assertSame([
            self::APPLY,
            self::APPLY,
            ['wait', '--for=condition=complete', $job, "--namespace={$namespace}", '--timeout=10s'],
            ['logs', $job, "--namespace={$namespace}", '--all-containers'],
            self::APPLY,
            ['rollout', 'status', 'deployment/web', "--namespace={$namespace}", '--timeout=300s'],
            ['rollout', 'status', 'deployment/worker', "--namespace={$namespace}", '--timeout=300s'],
            self::APPLY,
            [
                'delete', 'deployment', "--selector=opencloud.dev/environment={$this->environment->id},opencloud.dev/process,opencloud.dev/process notin (web,worker)",
                "--namespace={$namespace}", '--ignore-not-found', '--wait=false',
            ],
            [
                'delete', 'cronjob', "--selector=opencloud.dev/environment={$this->environment->id},opencloud.dev/process,opencloud.dev/process notin (scheduler)",
                "--namespace={$namespace}", '--ignore-not-found', '--wait=false',
            ],
            [
                'delete', 'horizontalpodautoscaler', "--selector=opencloud.dev/environment={$this->environment->id},opencloud.dev/process",
                "--namespace={$namespace}", '--ignore-not-found', '--wait=false',
            ],
            ['get', 'pods', "--selector=opencloud.dev/environment={$this->environment->id},opencloud.dev/process in (web,worker),opencloud.dev/deployment!={$this->new->id}", "--namespace={$namespace}", '-o', 'name'],
            ['exec', '-i', 'deploy/caddy', '--namespace=opencloud', '--', 'sh', '-c', 'cat > /tmp/caddy.json && wget -q -O- --header "Content-Type: application/json" --post-file /tmp/caddy.json http://127.0.0.1:2019/load'],
            ['get', 'secrets', "--selector=opencloud.dev/application={$this->environment->application_id},opencloud.dev/environment={$this->environment->id}", "--namespace={$namespace}", '--ignore-not-found', '-o', 'json'],
            ['delete', 'secret', 'env', "--namespace={$namespace}", '--ignore-not-found', '--wait=false'],
            ['exec', 'deploy/web', "--namespace={$namespace}", '--container=web', '--', 'php', '-r', self::HOME_PAGE_CHECK],
        ], array_column($this->kubectl->calls, 'args'));

        $applied = $this->kubectl->appliedManifests();
        $this->assertSame(
            ['Namespace', 'Secret', 'Job', 'Deployment', 'Deployment', 'Service', 'CronJob'],
            array_column($applied, 'kind'),
        );
        $this->assertSame([['secretRef' => ['name' => "env-{$this->new->id}"]]], $applied[6]['spec']['jobTemplate']['spec']['template']['spec']['containers'][0]['envFrom']);
        $this->assertSame(
            ['APP_DEBUG' => 'false', 'APP_ENV' => 'production', 'DB_PASSWORD' => 'hunter22', 'LOG_CHANNEL' => 'stderr'],
            array_map(base64_decode(...), $applied[1]['data']),
        );
        $this->assertSame("env-{$this->new->id}", $applied[1]['metadata']['name']);
        $this->assertSame([['secretRef' => ['name' => "env-{$this->new->id}"]]], $applied[2]['spec']['template']['spec']['containers'][0]['envFrom']);
        $this->assertSame([['secretRef' => ['name' => "env-{$this->new->id}"]]], $applied[3]['spec']['template']['spec']['containers'][0]['envFrom']);
        $this->assertSame($image, $applied[3]['spec']['template']['spec']['containers'][0]['image']);

        $new = $this->new->fresh();
        $this->assertSame(DeploymentStatus::LIVE, $new->status);
        $this->assertSame(DeploymentStatus::SUPERSEDED, $this->old->fresh()->status);
        $this->assertSame($image, $new->image);
        $this->assertNull($new->warning);
        $this->assertSame('2026-10-23 12:00:00', $new->started_at->toDateTimeString());
        $this->assertSame('2026-10-23 12:00:00', $new->finished_at->toDateTimeString());
        $this->assertSame(
            "2026-10-23T12:00:00Z \x1epreparing_build\n".
            '2026-10-23T12:00:00Z Cloning acme/shop at '.self::SHA.".\n".
            "2026-10-23T12:00:00Z \x1erunning_build\n".
            "2026-10-23T12:00:00Z Building {$image} from .opencloud.Dockerfile.\n".
            "2026-10-23T12:00:00Z #9 DONE 3.1s\n".
            "2026-10-23T12:00:00Z \x1epushing_application\n".
            "2026-10-23T12:00:00Z The cluster pulls the image from the local registry. Nothing to push.\n".
            "2026-10-23T12:00:00Z \x1epreparing_deploy\n".
            "2026-10-23T12:00:00Z Applied the namespace and the env Secret.\n".
            "2026-10-23T12:00:00Z \x1erunning_deploy_commands\n".
            "2026-10-23T12:00:00Z Running the deploy commands: php artisan migrate --force\n".
            "2026-10-23T12:00:00Z Migrating: create_users_table\n".
            "2026-10-23T12:00:00Z connected with ********\n".
            "2026-10-23T12:00:00Z \x1eupdating_stack\n".
            "2026-10-23T12:00:00Z Starting the new version.\n".
            "2026-10-23T12:00:00Z \x1erouting_traffic\n".
            "2026-10-23T12:00:00Z The deployment is live.\n",
            $new->log,
        );
        $this->assertSame(
            ['preparing_build', 'running_build', 'pushing_application', 'preparing_deploy', 'running_deploy_commands', 'updating_stack', 'routing_traffic'],
            array_column(DeploymentSteps::of($new), 'key'),
        );
        $this->assertSame(array_fill(0, 7, 'finished'), array_column(DeploymentSteps::of($new), 'status'));
    }

    public function test_a_deploy_after_a_change_of_the_primary_domain_gives_the_app_its_url_and_the_probe_its_host(): void
    {
        Domain::factory()->for($this->environment)->create([
            'hostname' => 'shop-production.203.0.113.10.sslip.io', 'generated' => true, 'dns_status' => DnsStatus::OK, 'certificate_status' => 'issued',
        ]);
        $domain = Domain::factory()->for($this->environment)->create(['hostname' => 'shop.example.com']);
        app(SetPrimaryDomain::class)->execute($domain, User::factory()->admin()->create());

        RunDeployment::dispatchSync($this->new);

        $applied = $this->kubectl->appliedManifests();
        $this->assertSame('https://shop.example.com', base64_decode($applied[1]['data']['APP_URL']));
        $this->assertSame(
            [['name' => 'Host', 'value' => 'shop.example.com']],
            $applied[3]['spec']['template']['spec']['containers'][0]['readinessProbe']['httpGet']['httpHeaders'],
        );
    }

    public function test_the_deployment_goes_live_only_after_the_old_pods_stopped(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $checks = 0;
        $this->kubectl->respond('get pods --selector=opencloud.dev/environment', function () use (&$checks) {
            return new KubectlResult(0, ++$checks < 3 ? "pod/web-old-abc12\n" : '');
        });

        RunDeployment::dispatchSync($this->new);

        $this->assertSame(3, $checks);
        $this->assertSame(DeploymentStatus::LIVE, $this->new->fresh()->status);
        $this->assertSame('2026-10-23 12:00:04', $this->new->fresh()->finished_at->toDateTimeString());
        $this->assertStringContainsString("2026-10-23T12:00:00Z Waiting for the old pods to stop.\n2026-10-23T12:00:04Z The deployment is live.\n", $this->new->fresh()->log);
    }

    public function test_old_pods_that_do_not_stop_in_a_minute_do_not_keep_the_deployment_from_going_live(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $this->kubectl->respond('get pods --selector=opencloud.dev/environment', new KubectlResult(0, "pod/web-old-abc12\n"));

        RunDeployment::dispatchSync($this->new);

        $this->assertSame(DeploymentStatus::LIVE, $this->new->fresh()->status);
        $this->assertStringContainsString("The old pods did not stop within 60 seconds. Kubernetes stops them later.\n", $this->new->fresh()->log);
    }

    public function test_the_processes_in_the_list_roll_out_and_the_deployments_of_removed_ones_are_deleted(): void
    {
        $this->environment->processes()->delete();
        Process::factory()->for($this->environment)->create(['name' => 'horizon', 'command' => 'php artisan horizon', 'min_instances' => 2, 'max_instances' => 2]);

        RunDeployment::dispatchSync($this->new);

        $namespace = "oc-env-{$this->environment->id}";
        $this->assertSame(DeploymentStatus::LIVE, $this->new->fresh()->status);
        $this->assertSame([
            ['rollout', 'status', 'deployment/web', "--namespace={$namespace}", '--timeout=300s'],
            ['rollout', 'status', 'deployment/horizon', "--namespace={$namespace}", '--timeout=300s'],
        ], array_column($this->kubectl->callsStartingWith('rollout'), 'args'));
        $this->assertSame([[
            'delete', 'deployment', "--selector=opencloud.dev/environment={$this->environment->id},opencloud.dev/process,opencloud.dev/process notin (web,horizon)",
            "--namespace={$namespace}", '--ignore-not-found', '--wait=false',
        ]], array_column($this->kubectl->callsStartingWith('delete deployment'), 'args'));

        $horizon = collect($this->kubectl->appliedManifests())->firstWhere('metadata.name', 'horizon');
        $this->assertSame(
            [2, ['type' => 'RollingUpdate', 'rollingUpdate' => ['maxUnavailable' => 0, 'maxSurge' => 1]], ['sh', '-c', 'exec php artisan horizon']],
            [$horizon['spec']['replicas'], $horizon['spec']['strategy'], $horizon['spec']['template']['spec']['containers'][0]['command']],
        );
    }

    public function test_a_process_that_changed_kind_loses_the_object_of_its_old_kind_on_the_next_deploy(): void
    {
        $this->environment->processes()->where('name', 'worker')->sole()->update([
            'kind' => ProcessKind::SCHEDULED, 'schedule' => '*/5 * * * *', 'min_instances' => null, 'max_instances' => null, 'cpu_target' => null,
        ]);
        $this->environment->processes()->where('name', 'scheduler')->sole()->update([
            'kind' => ProcessKind::LONG_RUNNING, 'command' => 'php artisan schedule:work', 'schedule' => null, 'min_instances' => 1, 'max_instances' => 1, 'cpu_target' => 70,
        ]);

        RunDeployment::dispatchSync($this->new);

        $namespace = "oc-env-{$this->environment->id}";
        $environment = "opencloud.dev/environment={$this->environment->id}";
        $this->assertSame(DeploymentStatus::LIVE, $this->new->fresh()->status);
        $this->assertSame(
            [['rollout', 'status', 'deployment/web', "--namespace={$namespace}", '--timeout=300s'], ['rollout', 'status', 'deployment/scheduler', "--namespace={$namespace}", '--timeout=300s']],
            array_column($this->kubectl->callsStartingWith('rollout'), 'args'),
        );
        $this->assertSame(
            [['Deployment', 'web'], ['Deployment', 'scheduler'], ['Service', 'web'], ['CronJob', 'worker']],
            array_map(fn (array $m) => [$m['kind'], $m['metadata']['name']], array_slice($this->kubectl->appliedManifests(), 3)),
        );
        $this->assertSame([
            ['delete', 'deployment', "--selector={$environment},opencloud.dev/process,opencloud.dev/process notin (web,scheduler)", "--namespace={$namespace}", '--ignore-not-found', '--wait=false'],
            ['delete', 'cronjob', "--selector={$environment},opencloud.dev/process,opencloud.dev/process notin (worker)", "--namespace={$namespace}", '--ignore-not-found', '--wait=false'],
            ['delete', 'horizontalpodautoscaler', "--selector={$environment},opencloud.dev/process", "--namespace={$namespace}", '--ignore-not-found', '--wait=false'],
        ], array_values(array_filter(array_column($this->kubectl->callsStartingWith('delete'), 'args'), fn (array $args) => $args[1] !== 'secret')));
    }

    public function test_the_laravel_scheduler_that_moved_from_a_cronjob_to_schedule_work_runs_as_a_deployment_on_the_next_deploy(): void
    {
        $this->environment->processes()->where('name', 'scheduler')->sole()->update([
            'kind' => ProcessKind::LONG_RUNNING, 'command' => 'php artisan schedule:work', 'schedule' => null, 'min_instances' => 1, 'max_instances' => 1, 'cpu_target' => 70,
        ]);

        RunDeployment::dispatchSync($this->new);

        $namespace = "oc-env-{$this->environment->id}";
        $scheduler = collect($this->kubectl->appliedManifests())->firstWhere('metadata.name', 'scheduler');
        $this->assertSame(
            ['Deployment', 1, ['sh', '-c', 'exec php artisan schedule:work']],
            [$scheduler['kind'], $scheduler['spec']['replicas'], $scheduler['spec']['template']['spec']['containers'][0]['command']],
        );
        $this->assertNotContains('CronJob', array_column($this->kubectl->appliedManifests(), 'kind'));
        $this->assertSame([[
            'delete', 'cronjob', "--selector=opencloud.dev/environment={$this->environment->id},opencloud.dev/process",
            "--namespace={$namespace}", '--ignore-not-found', '--wait=false',
        ]], array_column($this->kubectl->callsStartingWith('delete cronjob'), 'args'));
    }

    public function test_a_failing_deploy_command_fails_the_deployment_and_keeps_the_old_one_live(): void
    {
        $this->kubectl->respond('wait --for=condition=complete', new KubectlResult(1, '', 'error: timed out waiting for the condition'));
        $this->kubectl->respond('wait --for=condition=failed', new KubectlResult(0, 'job.batch/deploy condition met'));
        $this->kubectl->respond('logs job/', new KubectlResult(0, "SQLSTATE[HY000] [2002] Connection refused\n"));

        RunDeployment::dispatchSync($this->new);

        $this->assertSame([['new', 'building'], ['new', 'deploying'], ['new', 'failed']], $this->moves);
        $this->assertSame(DeploymentStatus::FAILED, $this->new->fresh()->status);
        $this->assertSame(DeploymentStatus::LIVE, $this->old->fresh()->status);
        $this->assertSame(['Namespace', 'Secret', 'Job'], array_column($this->kubectl->appliedManifests(), 'kind'));
        $this->assertSame([], $this->kubectl->callsStartingWith('rollout'));
        $this->assertSame([], $this->kubectl->callsStartingWith('exec'));
        $this->assertStringEndsWith(
            "2026-10-23T12:00:00Z SQLSTATE[HY000] [2002] Connection refused\n".
            "2026-10-23T12:00:00Z The deployment failed. A deploy command failed.\n",
            $this->new->fresh()->log,
        );
    }

    public function test_a_failed_rollout_is_undone_and_fails_the_deployment(): void
    {
        $this->kubectl->respond('rollout status deployment/worker', new KubectlResult(1, '', 'error: deployment "worker" exceeded its progress deadline'));

        RunDeployment::dispatchSync($this->new);

        $namespace = "oc-env-{$this->environment->id}";
        $this->assertSame([['new', 'building'], ['new', 'deploying'], ['new', 'failed']], $this->moves);
        $this->assertSame(DeploymentStatus::LIVE, $this->old->fresh()->status);
        $this->assertSame([
            ['rollout', 'status', 'deployment/web', "--namespace={$namespace}", '--timeout=300s'],
            ['rollout', 'status', 'deployment/worker', "--namespace={$namespace}", '--timeout=300s'],
            ['rollout', 'undo', 'deployment/web', "--namespace={$namespace}"],
            ['rollout', 'undo', 'deployment/worker', "--namespace={$namespace}"],
        ], array_column($this->kubectl->callsStartingWith('rollout'), 'args'));
        $this->assertSame([], $this->kubectl->callsStartingWith('delete'));
        $this->assertSame(
            ['Namespace', 'Secret', 'Job', 'Deployment', 'Deployment', 'Service'],
            array_column($this->kubectl->appliedManifests(), 'kind'),
            'The CronJob keeps the old schedule, because it has no rollout to undo.',
        );
        $this->assertStringEndsWith(
            "2026-10-23T12:00:00Z error: deployment \"worker\" exceeded its progress deadline\n".
            "2026-10-23T12:00:00Z The deployment failed. The new worker pods did not become ready. Opencloud rolled the processes back.\n",
            $this->new->fresh()->log,
        );
    }

    public function test_going_live_keeps_the_live_secret_and_the_two_newest_and_deletes_the_rest(): void
    {
        $failed = Deployment::factory()->for($this->environment)->count(2)->create(['status' => DeploymentStatus::FAILED]);
        $newest = $failed->last();
        $this->kubectl->respond('get secrets', $this->secretList([
            'env', "env-{$this->old->id}", "env-{$this->new->id}", "env-{$failed->first()->id}", "env-{$newest->id}",
        ]));

        RunDeployment::dispatchSync($this->new);

        $this->assertSame(DeploymentStatus::LIVE, $this->new->fresh()->status);
        $this->assertSame(
            [['secret', "env-{$this->old->id}"], ['secret', 'env']],
            array_map(fn (array $call) => array_slice($call['args'], 1, 2), $this->kubectl->callsStartingWith('delete secret')),
        );
    }

    public function test_a_failed_deployment_leaves_every_secret_in_place(): void
    {
        $this->kubectl->respond('rollout status deployment/web', new KubectlResult(1, '', 'error: timed out'));

        RunDeployment::dispatchSync($this->new);

        $this->assertSame(DeploymentStatus::FAILED, $this->new->fresh()->status);
        $this->assertSame([], $this->kubectl->callsStartingWith('get secrets'));
        $this->assertSame([], $this->kubectl->callsStartingWith('delete secret'));
    }

    public function test_the_deployment_waits_for_its_datastores_before_it_applies_the_env_secret(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $attachment = DatastoreAttachment::factory()->for($this->environment)->create(['status' => DatastoreStatus::PROVISIONING]);
        $sleeps = 0;
        Sleep::whenFakingSleep(function () use ($attachment, &$sleeps) {
            if (++$sleeps === 3) {
                $attachment->update(['status' => DatastoreStatus::READY]);
            }
        });

        RunDeployment::dispatchSync($this->new);

        $this->assertSame(DeploymentStatus::LIVE, $this->new->fresh()->status);
        Sleep::assertSleptTimes(3);
        $this->assertStringContainsString(
            "2026-10-23T12:00:00Z Waiting for the datastores: mysql.\n".
            "2026-10-23T12:00:15Z Applied the namespace and the env Secret.\n".
            "2026-10-23T12:00:15Z \x1erunning_deploy_commands\n".
            "2026-10-23T12:00:15Z Running the deploy commands: php artisan migrate --force\n",
            $this->new->fresh()->log,
        );
    }

    public function test_a_deploy_replaces_the_stale_env_rows_of_each_attachment_before_it_applies_the_env_secret(): void
    {
        $datastore = Datastore::factory()->for($this->environment->application->cluster)->create(['kind' => DatastoreKind::MYSQL]);
        $attachment = DatastoreAttachment::factory()->for($datastore)->for($this->environment)->create([
            'status' => DatastoreStatus::READY, 'name' => 'main', 'is_default' => true, 'host' => 'mysql-1.oc-datastores.svc.cluster.local', 'port' => 3306,
            'database_name' => 'env_1', 'username' => 'env_1', 'password' => 'p/ss',
        ]);
        $this->environment->envVars()->create([
            'key' => 'DATABASE_URL', 'value' => 'mysql://env_1:p%2Fss@mysql-1.oc-datastores.svc.cluster.local:3306/env_1', 'is_secret' => true,
            'source' => EnvVarSource::DATASTORE, 'datastore_attachment_id' => $attachment->id,
        ]);

        RunDeployment::dispatchSync($this->new);

        $this->assertSame(DeploymentStatus::LIVE, $this->new->fresh()->status);
        $this->assertSame(
            ['DB_CONNECTION', 'DB_DATABASE', 'DB_HOST', 'DB_PASSWORD', 'DB_PORT', 'DB_USERNAME'],
            $attachment->envVars()->orderBy('key')->pluck('key')->all(),
        );
        $this->assertSame([
            'APP_DEBUG' => 'false',
            'APP_ENV' => 'production',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'env_1',
            'DB_HOST' => 'mysql-1.oc-datastores.svc.cluster.local',
            'DB_PASSWORD' => 'hunter22',
            'DB_PORT' => '3306',
            'DB_USERNAME' => 'env_1',
            'LOG_CHANNEL' => 'stderr',
        ], array_map(base64_decode(...), $this->kubectl->appliedManifests()[1]['data']));
    }

    public function test_a_failed_datastore_fails_the_deployment_with_its_error(): void
    {
        DatastoreAttachment::factory()->for($this->environment)->create([
            'status' => DatastoreStatus::FAILED,
            'error' => "ERROR 1045 (28000): Access denied for user 'root'@'localhost'",
        ]);

        RunDeployment::dispatchSync($this->new);

        $this->assertSame([['new', 'building'], ['new', 'deploying'], ['new', 'failed']], $this->moves);
        $this->assertSame(DeploymentStatus::LIVE, $this->old->fresh()->status);
        $this->assertSame([], $this->kubectl->callsStartingWith('apply'));
        $this->assertStringEndsWith(
            "2026-10-23T12:00:00Z The deployment failed. The mysql datastore failed. ERROR 1045 (28000): Access denied for user 'root'@'localhost'\n",
            $this->new->fresh()->log,
        );
    }

    public function test_a_datastore_that_stays_pending_fails_the_deployment_after_ten_minutes(): void
    {
        Sleep::fake(syncWithCarbon: true);
        DatastoreAttachment::factory()->for($this->environment)->create(['status' => DatastoreStatus::PENDING]);

        RunDeployment::dispatchSync($this->new);

        Sleep::assertSleptTimes(120);
        $this->assertSame(DeploymentStatus::FAILED, $this->new->fresh()->status);
        $this->assertSame([], $this->kubectl->callsStartingWith('apply'));
        $this->assertStringEndsWith(
            "2026-10-23T12:00:00Z Waiting for the datastores: mysql.\n".
            "2026-10-23T12:10:00Z The deployment failed. The datastores did not become ready in 10 minutes. Still waiting for: mysql.\n",
            $this->new->fresh()->log,
        );
    }

    public function test_a_server_error_on_the_home_page_leaves_the_deployment_live_with_a_warning(): void
    {
        $this->kubectl->respond('exec deploy/web', new KubectlResult(0, 'HTTP/1.1 500 Internal Server Error'));

        RunDeployment::dispatchSync($this->new);

        $new = $this->new->fresh();
        $this->assertSame(DeploymentStatus::LIVE, $new->status);
        $this->assertSame('The home page returned HTTP 500.', $new->warning);
        $this->assertStringEndsWith("2026-10-23T12:00:00Z Warning: the home page returned HTTP 500. Read the web logs.\n", $new->log);
    }

    public function test_a_deployment_that_a_dead_worker_left_behind_fails_instead_of_running_again(): void
    {
        $this->new->forceFill(['status' => DeploymentStatus::BUILDING])->save();
        $this->moves = [];

        RunDeployment::dispatchSync($this->new);

        $this->assertSame([['new', 'failed']], $this->moves);
        $this->assertSame([], $this->kubectl->calls);
        $this->assertSame("2026-10-23T12:00:00Z The deployment failed. The worker stopped during the deployment.\n", $this->new->fresh()->log);
    }

    public function test_a_build_failure_fails_the_deployment_before_kubernetes(): void
    {
        $processes = FakeProcesses::install()->withLaravelRepository();
        $processes->buildExitCode = 1;

        RunDeployment::dispatchSync($this->new);

        $this->assertSame([['new', 'building'], ['new', 'failed']], $this->moves);
        $this->assertSame([], $this->kubectl->calls);
        $this->assertStringEndsWith("2026-10-23T12:00:00Z The deployment failed. buildctl exited with code 1.\n", $this->new->fresh()->log);
    }

    /** @param  list<string>  $names */
    private function secretList(array $names): KubectlResult
    {
        return new KubectlResult(0, json_encode(['items' => array_map(fn (string $name) => ['metadata' => ['name' => $name]], $names)]));
    }
}
