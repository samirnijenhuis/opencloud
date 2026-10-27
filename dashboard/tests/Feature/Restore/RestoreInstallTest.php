<?php

namespace Tests\Feature\Restore;

use App\Domains\Applications\Models\Application;
use App\Domains\Backups\Models\BackupTarget;
use App\Domains\CloudProviders\Models\ProviderCredential;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Jobs\RunDeployment;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Restore\Enums\RestoreStep;
use App\Domains\Restore\RestoreState;
use App\Domains\Routing\Enums\DnsMode;
use App\Domains\Routing\Enums\DnsStatus;
use App\Domains\Routing\Jobs\CheckDomain;
use App\Domains\Routing\Models\Domain;
use App\Support\Kube\FakeKubectl;
use App\Support\Kube\KubectlResult;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RestoreInstallTest extends TestCase
{
    use RefreshDatabase;

    private const LIVE_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private FakeKubectl $kubectl;

    private Filesystem $bucket;

    private Environment $production;

    private Datastore $mysql;

    private Datastore $rustfs;

    private string $tuesdayDump;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kubectl = FakeKubectl::install();
        config(['opencloud.data_path' => Storage::fake('opencloud-data')->path('')]);
        $this->bucket = Storage::fake('bucket');
        Storage::extend('s3', fn () => $this->bucket);
        Queue::fake([RunDeployment::class, CheckDomain::class]);

        // The records of the lost server, as the dashboard copy of the night before brings them back.
        $this->travelTo(Carbon::parse('2026-10-22 10:00:00'));
        $local = Cluster::factory()->create(['kind' => ClusterKind::Local, 'name' => 'local', 'ingress_ip' => '203.0.113.20']);
        BackupTarget::factory()->create(['bucket' => 'oc-backups']);
        $shop = Application::factory()->for($local)->create(['name' => 'shop']);
        $this->production = Environment::factory()->for($shop)->create(['name' => 'production']);
        Deployment::factory()->for($this->production)->create(['status' => DeploymentStatus::Failed]);
        Deployment::factory()->for($this->production)->create(['status' => DeploymentStatus::Live, 'commit_sha' => self::LIVE_SHA]);
        $staging = Environment::factory()->for($shop)->create(['name' => 'staging']);
        Deployment::factory()->for($staging)->create(['status' => DeploymentStatus::Building]);
        $preview = Environment::factory()->for($shop)->create(['name' => 'pr-7', 'kind' => EnvironmentKind::Preview]);
        Deployment::factory()->for($preview)->create(['status' => DeploymentStatus::Live]);
        $elsewhere = Environment::factory()->for(Application::factory()->for(Cluster::factory()->create(['name' => 'eu']))->create(['name' => 'api']))->create(['name' => 'production']);
        Deployment::factory()->for($elsewhere)->create(['status' => DeploymentStatus::Live]);

        $this->mysql = Datastore::factory()->for($local)->create(['kind' => DatastoreKind::Mysql, 'status' => DatastoreStatus::Ready, 'root_password' => 'mysql-root-pw']);
        $this->rustfs = Datastore::factory()->for($local)->create(['kind' => DatastoreKind::Rustfs, 'version' => '1.0.3', 'status' => DatastoreStatus::Ready, 'root_password' => 'rustfs-root-pw']);
        $env = $this->production->id;
        DatastoreAttachment::factory()->for($this->mysql)->for($this->production)->create(['database_name' => "env_{$env}", 'username' => "env_{$env}", 'status' => DatastoreStatus::Ready]);
        DatastoreAttachment::factory()->for($this->rustfs)->for($this->production)->create(['database_name' => "env-{$env}", 'username' => "env-{$env}", 'status' => DatastoreStatus::Ready]);
        Domain::factory()->for($this->production)->create(['hostname' => 'shop.example.com', 'dns_status' => DnsStatus::Ok]);

        $this->tuesdayDump = gzencode("INSERT INTO orders VALUES (2);\n");
        $this->bucket->put("backups/{$this->mysql->id}/{$env}/20261021-030000.sql.gz", gzencode("INSERT INTO orders VALUES (1);\n"));
        $this->bucket->put("backups/{$this->mysql->id}/{$env}/20261022-030000.sql.gz", $this->tuesdayDump);
        $this->bucket->put("backups/{$this->mysql->id}/{$env}/20261023-130000.sql.gz", gzencode("-- an empty database, dumped on the new server\n"));
        $this->bucket->put("backups/{$this->mysql->id}/{$env}/notes.txt", 'not a dump');
        $this->bucket->put("rustfs/{$this->rustfs->id}/env-{$env}/photo.jpg", 'jpeg');

        $this->travelTo(Carbon::parse('2026-10-23 12:00:00'));
        RestoreState::begin();
    }

    public function test_the_steps_run_in_order_and_use_the_newest_dump_from_before_the_restore(): void
    {
        $this->artisan('opencloud:restore')->assertExitCode(0);

        $env = $this->production->id;
        $this->assertSame([
            "provision mysql-{$this->mysql->id}",
            "provision rustfs-{$this->rustfs->id}",
            "restore env_{$env} from the dump of 2026-10-22",
            "copy job/rustfs-{$this->rustfs->id}-copy-restore",
            'load the Caddy config',
        ], $this->milestones());
        $job = collect($this->kubectl->appliedManifests())->firstWhere('kind', 'Job');
        $this->assertSame(['copy', "target:oc-backups/rustfs/{$this->rustfs->id}", 'source:'], $job['spec']['template']['spec']['containers'][0]['args']);
        Queue::assertPushed(RunDeployment::class, 1);
        Queue::assertPushed(RunDeployment::class, fn (RunDeployment $job) => $job->deployment->environment_id === $env && $job->deployment->commit_sha === self::LIVE_SHA);
        $this->assertTrue(RestoreState::load()->finished());
    }

    public function test_old_deployments_of_the_local_cluster_are_retired_with_a_log_line(): void
    {
        $this->artisan('opencloud:restore')->assertExitCode(0);

        $this->assertSame([
            ['shop', 'production', 'failed', null],
            ['shop', 'production', 'superseded', 'Opencloud restored the install on a new server. This deployment does not run there.'],
            ['shop', 'staging', 'failed', 'The server was lost during this deployment.'],
            ['shop', 'pr-7', 'superseded', 'Opencloud restored the install on a new server. This deployment does not run there.'],
            ['api', 'production', 'live', null],
            ['shop', 'production', 'queued', null],
        ], Deployment::query()->with('environment.application')->orderBy('id')->get()->map(fn (Deployment $deployment) => [
            $deployment->environment->application->name,
            $deployment->environment->name,
            $deployment->status->value,
            $deployment->log === null ? null : substr(trim($deployment->log), 21),
        ])->all());
    }

    public function test_a_second_run_after_a_failed_step_goes_on_from_that_step(): void
    {
        $this->kubectl->respond('wait --for=condition=complete', new KubectlResult(1, '', 'timed out'));

        $this->artisan('opencloud:restore')->assertExitCode(0);
        $afterFailure = $this->statuses();
        $failure = RestoreState::load()->detail(RestoreStep::RestoreBuckets);
        Queue::assertNothingPushed();

        $this->kubectl->respond('wait --for=condition=complete', new KubectlResult(0, 'condition met'));
        $this->artisan('opencloud:restore')->assertExitCode(0);
        $this->artisan('opencloud:restore')->expectsOutput('The restore is done.')->assertExitCode(0);

        $this->assertSame(['done', 'done', 'done', 'failed', 'pending', 'pending', 'pending'], $afterFailure);
        $this->assertSame("The copy of RustFS {$this->rustfs->id} failed. Read its log with: kubectl -n oc-datastores logs job/rustfs-{$this->rustfs->id}-copy-restore", $failure);
        $this->assertSame(1, collect($this->milestones())->filter(fn (string $milestone) => str_starts_with($milestone, 'restore '))->count());
        Queue::assertPushed(RunDeployment::class, 1);
        $this->assertSame(['done', 'done', 'done', 'done', 'done', 'done', 'done'], $this->statuses());
    }

    public function test_the_status_command_prints_each_step_and_the_new_deployments(): void
    {
        $this->artisan('opencloud:restore');
        $deployment = Deployment::query()->latest('id')->first();
        $env = $this->production->id;

        $this->artisan('opencloud:restore-status')
            ->expectsOutput('The restore started at 2026-10-23 12:00:00 UTC.')
            ->expectsOutput('done     Retire the old deployments  2 superseded, 1 failed.')
            ->expectsOutput('done     Provision the datastores    2 ready.')
            ->expectsOutput("done     Restore the databases       env_{$env} from backups/{$this->mysql->id}/{$env}/20261022-030000.sql.gz.")
            ->expectsOutput("done     Copy the buckets back       RustFS {$this->rustfs->id} from rustfs/{$this->rustfs->id}.")
            ->expectsOutput('done     Deploy the environments     Started 1 of 1 deployments.')
            ->expectsOutput('done     Check the domains           Point the DNS of shop.example.com at 203.0.113.20.')
            ->expectsOutput('done     Sync Caddy                  Caddy has the full config.')
            ->expectsOutput("Deployment {$deployment->id} of shop/production: queued")
            ->expectsOutput('The restore is done.')
            ->assertExitCode(0);
    }

    public function test_the_domains_check_again_and_a_cloudflare_record_points_at_the_new_server(): void
    {
        ProviderCredential::factory()->create(['provider' => 'cloudflare', 'token' => 'cf-token', 'details' => ['zones' => [['id' => 'zone-1', 'name' => 'example.org']]]]);
        Domain::factory()->for($this->production)->create(['hostname' => 'www.example.org', 'dns_mode' => DnsMode::Cloudflare, 'dns_status' => DnsStatus::Proxied]);
        Http::fake([
            'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => [['id' => 'rec-1', 'content' => '198.51.100.1', 'proxied' => true]]]),
            'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records/rec-1' => Http::response(['success' => true, 'result' => ['id' => 'rec-1']]),
        ]);

        $this->artisan('opencloud:restore')->assertExitCode(0);

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request->data()['content'] === '203.0.113.20' && $request->data()['proxied'] === true);
        $this->assertSame(['shop.example.com' => 'pending', 'www.example.org' => 'pending'], Domain::query()->orderBy('hostname')->get()->mapWithKeys(fn (Domain $domain) => [$domain->hostname => $domain->dns_status->value])->all());
        $this->assertSame(['shop.example.com', 'www.example.org'], Queue::pushed(CheckDomain::class)->map(fn (CheckDomain $job) => $job->domain->hostname)->sort()->values()->all());
        $this->assertSame(
            'Point the DNS of shop.example.com, www.example.org at 203.0.113.20. Cloudflare points www.example.org at 203.0.113.20.',
            RestoreState::load()->detail(RestoreStep::CheckDomains),
        );
    }

    public function test_a_server_without_a_started_restore_refuses_to_restore(): void
    {
        unlink(RestoreState::path());

        $this->artisan('opencloud:restore')
            ->expectsOutput('No restore started on this server. Run the installer with OPENCLOUD_RESTORE_FILE first.')
            ->assertExitCode(1);

        $this->assertSame([], $this->kubectl->calls);
    }

    /**
     * The calls that show each step, in the order kubectl received them.
     *
     * @return list<string>
     */
    private function milestones(): array
    {
        return collect($this->kubectl->calls)->map(function (array $call) {
            $line = implode(' ', $call['args']);

            return match (true) {
                str_starts_with($line, 'rollout status statefulset/') => 'provision '.substr($call['args'][2], strlen('statefulset/')),
                $call['stdin'] === $this->tuesdayDump => "restore env_{$this->production->id} from the dump of 2026-10-22",
                str_starts_with($line, 'wait --for=condition=complete') => 'copy '.$call['args'][2],
                str_contains($line, 'deploy/caddy') => 'load the Caddy config',
                default => null,
            };
        })->filter()->values()->all();
    }

    /** @return list<string> */
    private function statuses(): array
    {
        $state = RestoreState::load();

        return array_map(fn (RestoreStep $step) => $state->status($step)->value, RestoreStep::cases());
    }
}
