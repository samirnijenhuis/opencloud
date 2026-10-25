<?php

namespace Tests\Feature\Previews;

use App\Actions\AttachDatastore;
use App\Actions\CreateApplication;
use App\Actions\SetDefaultDatabase;
use App\Actions\SetEnvVar;
use App\Enums\ClusterKind;
use App\Enums\DatastoreKind;
use App\Enums\DeploymentStatus;
use App\Enums\EnvironmentKind;
use App\Enums\EnvVarAvailability;
use App\Enums\EnvVarSource;
use App\Jobs\CheckDomain;
use App\Jobs\ProvisionDatastoreAttachment;
use App\Jobs\RunDeployment;
use App\Jobs\SyncCaddy;
use App\Kube\FakeKubectl;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Cluster;
use App\Models\DatastoreAttachment;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvVar;
use App\Models\Process;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeProcesses;
use Tests\Feature\GitHub\WithGitHubApp;
use Tests\TestCase;

class PreviewLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use WithGitHubApp;

    private const OPENED_SHA = '1111111111111111111111111111111111111111';

    private const PUSHED_SHA = '2222222222222222222222222222222222222222';

    private FakeKubectl $kubectl;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RunDeployment::class, ProvisionDatastoreAttachment::class, CheckDomain::class, SyncCaddy::class]);
        $this->kubectl = FakeKubectl::install();
        FakeProcesses::install()->lsRemote = str_repeat('a', 40)."\trefs/heads/main\n";
        $owner = User::factory()->owner()->create();
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::Local, 'name' => 'local', 'ingress_ip' => '203.0.113.10']);

        $this->application = app(CreateApplication::class)->handle($owner, [
            'name' => 'Shop',
            'repository' => 'acme/shop',
            'cluster_id' => $cluster->id,
            'env' => [['key' => 'STRIPE_SECRET', 'value' => 'sk_live_production', 'is_secret' => true]],
        ]);
        app(AttachDatastore::class)->handle($this->environment('production'), $owner, DatastoreKind::Valkey);

        $template = $this->environment('preview-template');
        $template->update(['health_path' => '/health', 'deploy_commands' => ['php artisan migrate --force', 'php artisan db:seed --force']]);
        $template->processes()->where('name', 'worker')->delete();
        $template->processes()->create(['name' => 'horizon', 'command' => 'php artisan horizon', 'instances' => 2, 'exclusive' => false]);
        $setEnvVar = app(SetEnvVar::class);
        $setEnvVar->handle($template, $owner, 'MAIL_MAILER', 'log', false, EnvVarAvailability::Runtime);
        $setEnvVar->handle($template, $owner, 'STRIPE_SECRET', 'sk_test_preview', true, EnvVarAvailability::Runtime);
        $setEnvVar->handle($template, $owner, 'COMPOSER_AUTH', '{"github-oauth":{"github.com":"gho_x"}}', true, EnvVarAvailability::Build);

        $this->makeGitHubApp();
        $this->fakeGitHub();
        AuditEvent::query()->delete();
    }

    public function test_a_pull_request_opens_redeploys_and_closes_its_preview(): void
    {
        $this->postWebhook('pull_request', $this->pullRequest('opened', self::OPENED_SHA), delivery: 'd-1')->assertStatus(202);

        $preview = $this->environment('pr-12');
        $this->assertSame(
            [EnvironmentKind::Preview, 12, 'feature/login', false, 's', 1, 1, '/health', ['php artisan migrate --force', 'php artisan db:seed --force']],
            [$preview->kind, $preview->pr_number, $preview->branch, $preview->is_protected, $preview->size->value, $preview->min_instances, $preview->max_instances, $preview->health_path, $preview->deploy_commands],
        );
        $this->assertSame(
            [['scheduler', 'php artisan schedule:work', 1, true], ['horizon', 'php artisan horizon', 2, false]],
            $preview->processes->map(fn (Process $p) => [$p->name, $p->command, $p->instances, $p->exclusive])->all(),
        );
        $this->assertSame(
            ['pr_number' => 12, 'branch' => 'feature/login', 'from_fork' => false, 'processes' => ['scheduler', 'horizon']],
            AuditEvent::where('action', 'preview.created')->sole()->context,
        );

        $userEnv = $preview->envVars()->where('source', EnvVarSource::User)->orderBy('key')->get()
            ->map(fn (EnvVar $var) => [$var->key, $var->key === 'APP_KEY' ? 'generated' : $var->value, $var->is_secret, $var->available_at->value])
            ->all();
        $this->assertSame([
            ['APP_KEY', 'generated', true, 'runtime'],
            ['COMPOSER_AUTH', '{"github-oauth":{"github.com":"gho_x"}}', true, 'build'],
            ['MAIL_MAILER', 'log', false, 'runtime'],
            ['STRIPE_SECRET', 'sk_test_preview', true, 'runtime'],
        ], $userEnv);
        $appKey = $preview->envVars()->where('key', 'APP_KEY')->sole()->value;
        $this->assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/]{43}=$/', $appKey);
        $this->assertNotSame($this->environment('production')->envVars()->where('key', 'APP_KEY')->sole()->value, $appKey);

        $attachments = $preview->datastoreAttachments()->with('datastore')->orderBy('id')->get();
        $this->assertSame(
            [['mysql', "env_{$preview->id}", "env_{$preview->id}"], ['valkey', null, null]],
            $attachments->map(fn (DatastoreAttachment $a) => [$a->datastore->kind->value, $a->database_name, $a->username])->all(),
        );
        $this->assertSame(
            $this->environment('production')->datastoreAttachments()->orderBy('id')->pluck('datastore_id')->all(),
            $attachments->pluck('datastore_id')->all(),
        );
        $this->assertSame("env_{$preview->id}", $preview->envVars()->where('key', 'DB_DATABASE')->sole()->value);

        $this->assertSame(['pr-12-shop.203.0.113.10.sslip.io'], $preview->domains()->pluck('hostname')->all());
        Queue::assertPushed(CheckDomain::class, fn (CheckDomain $job) => $job->domain->hostname === 'pr-12-shop.203.0.113.10.sslip.io');

        $this->postWebhook('pull_request', $this->pullRequest('synchronize', self::PUSHED_SHA), delivery: 'd-2')->assertStatus(202);

        $this->assertSame(
            [[self::OPENED_SHA, DeploymentStatus::Queued, null], [self::PUSHED_SHA, DeploymentStatus::Queued, null]],
            $preview->deployments()->orderBy('id')->get()->map(fn (Deployment $d) => [$d->commit_sha, $d->status, $d->triggered_by])->all(),
        );
        Queue::assertPushed(RunDeployment::class, 3);
        $this->assertSame(1, Environment::query()->where('kind', EnvironmentKind::Preview)->count());

        $this->postWebhook('pull_request', $this->pullRequest('closed', self::PUSHED_SHA), delivery: 'd-3')->assertStatus(202);

        $mysql = $attachments[0];
        $valkey = $attachments[1];
        $this->assertSame([
            ['delete', 'namespace', "oc-env-{$preview->id}", '--ignore-not-found', '--wait=false'],
            ['exec', '-i', "mysql-{$mysql->datastore_id}-0", '--namespace=oc-datastores', '--container=mysql', '--', 'bash', '-c', 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root'],
            ['delete', 'deployment', "valkey-{$valkey->id}", '--namespace=oc-datastores', '--ignore-not-found', '--wait=false'],
            ['delete', 'service', "valkey-{$valkey->id}", '--namespace=oc-datastores', '--ignore-not-found', '--wait=false'],
            ['delete', 'secret', "valkey-{$valkey->id}", '--namespace=oc-datastores', '--ignore-not-found', '--wait=false'],
            ['delete', 'persistentvolumeclaim', "valkey-{$valkey->id}", '--namespace=oc-datastores', '--ignore-not-found', '--wait=false'],
        ], array_column($this->kubectl->calls, 'args'));
        $this->assertSame("DROP USER IF EXISTS 'env_{$preview->id}'@'%';\nDROP DATABASE IF EXISTS `env_{$preview->id}`;\n", $this->kubectl->calls[1]['stdin']);

        $this->assertNull(Environment::find($preview->id));
        $this->assertSame([0, 0, 0, 0, 0], [
            Process::where('environment_id', $preview->id)->count(),
            EnvVar::where('environment_id', $preview->id)->count(),
            DatastoreAttachment::where('environment_id', $preview->id)->count(),
            Domain::where('environment_id', $preview->id)->count(),
            Deployment::where('environment_id', $preview->id)->count(),
        ]);
        Queue::assertPushed(SyncCaddy::class, 1);

        $this->assertSame([
            'preview.created', 'preview.env_copied', 'datastore.attached', 'datastore.attached', 'domain.added', 'deployment.started',
            'deployment.started',
            'datastore.destroyed', 'datastore.destroyed', 'domain.removed', 'preview.destroyed',
        ], AuditEvent::query()->orderBy('id')->pluck('action')->all());
        $this->assertNull(AuditEvent::query()->whereNotNull('user_id')->first());
        $audit = AuditEvent::all()->toJson();
        $this->assertStringNotContainsString('sk_test_preview', $audit);
        $this->assertStringNotContainsString(substr($appKey, 7), $audit);
    }

    public function test_a_preview_copies_the_database_names_and_the_default_of_production(): void
    {
        $production = $this->environment('production');
        $owner = User::factory()->owner()->create();
        $reports = app(AttachDatastore::class)->handle($production, $owner, DatastoreKind::Postgres, name: 'reports');
        app(SetDefaultDatabase::class)->handle($reports, $owner);

        $this->postWebhook('pull_request', $this->pullRequest('opened', self::OPENED_SHA), delivery: 'd-1')->assertStatus(202);

        $preview = $this->environment('pr-12');
        $this->assertSame(
            [['postgres', 'reports', true, "env_{$preview->id}_reports"], ['mysql', 'main', false, "env_{$preview->id}"], ['valkey', null, false, null]],
            $preview->datastoreAttachments()->with('datastore')->orderBy('id')->get()
                ->map(fn (DatastoreAttachment $a) => [$a->datastore->kind->value, $a->name, $a->is_default, $a->database_name])->all(),
        );
        $this->assertSame(
            ['pgsql', "env_{$preview->id}_reports"],
            [$preview->envVars()->where('key', 'DB_CONNECTION')->sole()->value, $preview->envVars()->where('key', 'DB_DATABASE')->sole()->value],
        );
        $this->assertStringEndsWith(":3306/env_{$preview->id}", $preview->envVars()->where('key', 'MAIN_DB_URL')->sole()->value);
    }

    public function test_a_preview_domain_gives_each_preview_a_subdomain(): void
    {
        $this->application->update(['preview_domain' => 'preview.example.com']);

        $this->postWebhook('pull_request', $this->pullRequest('opened', self::OPENED_SHA))->assertStatus(202);

        $this->assertSame(['pr-12.preview.example.com'], $this->environment('pr-12')->domains()->pluck('hostname')->all());
    }

    public function test_a_pull_request_from_a_fork_gets_no_preview_unless_the_application_allows_forks(): void
    {
        $fork = $this->pullRequest('opened', self::OPENED_SHA, headRepository: 'mallory/shop');

        $this->postWebhook('pull_request', $fork, delivery: 'd-1')->assertStatus(202);

        $this->assertSame(0, Environment::query()->where('kind', EnvironmentKind::Preview)->count());
        $this->assertSame(['preview.skipped', ['pr_number' => 12, 'reason' => 'fork']], [AuditEvent::sole()->action, AuditEvent::sole()->context]);

        $this->application->update(['allow_forks' => true]);
        $this->postWebhook('pull_request', $fork, delivery: 'd-2')->assertStatus(202);

        $this->assertSame(self::OPENED_SHA, $this->environment('pr-12')->deployments()->sole()->commit_sha);
    }

    public function test_switched_off_previews_create_nothing(): void
    {
        $this->application->update(['previews_enabled' => false]);

        $this->postWebhook('pull_request', $this->pullRequest('opened', self::OPENED_SHA))->assertStatus(202);

        $this->assertSame(0, Environment::query()->where('kind', EnvironmentKind::Preview)->count());
        $this->assertSame(['pr_number' => 12, 'reason' => 'previews_disabled'], AuditEvent::sole()->context);
    }

    public function test_over_the_limit_a_comment_says_so_and_nothing_is_created(): void
    {
        config(['opencloud.max_previews' => 1]);
        $this->fakeGitHub(more: ['api.github.com/repos/acme/shop/issues/13/comments' => Http::response(['id' => 9001], 201)]);
        $this->postWebhook('pull_request', $this->pullRequest('opened', self::OPENED_SHA), delivery: 'd-1');

        $this->postWebhook('pull_request', $this->pullRequest('opened', self::OPENED_SHA, number: 13), delivery: 'd-2')->assertStatus(202);
        $this->postWebhook('pull_request', $this->pullRequest('synchronize', self::PUSHED_SHA, number: 13), delivery: 'd-3')->assertStatus(202);

        $this->assertSame(['pr-12'], Environment::query()->where('kind', EnvironmentKind::Preview)->pluck('name')->all());
        $comments = Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/comments'))->values();
        $this->assertCount(1, $comments);
        $this->assertSame('https://api.github.com/repos/acme/shop/issues/13/comments', $comments[0][0]->url());
        $this->assertSame('Bearer '.self::TOKEN, $comments[0][0]->header('Authorization')[0]);
        $this->assertSame(
            ['body' => 'Opencloud did not create a preview for this pull request. Shop already runs 1 previews, which is the limit. Close a pull request that has a preview, then push to this pull request to try again.'],
            $comments[0][0]->data(),
        );
        $this->assertSame(['pr_number' => 13, 'limit' => 1], AuditEvent::query()->where('action', 'preview.limit_reached')->first()->context);
    }

    public function test_a_close_waits_while_a_deployment_of_the_preview_still_builds(): void
    {
        $this->postWebhook('pull_request', $this->pullRequest('opened', self::OPENED_SHA), delivery: 'd-1');
        $preview = $this->environment('pr-12');
        $preview->deployments()->sole()->moveTo(DeploymentStatus::Building);

        $this->postWebhook('pull_request', $this->pullRequest('closed', self::OPENED_SHA), delivery: 'd-2')->assertStatus(202);

        $this->assertSame([], $this->kubectl->calls);
        $this->assertNotNull($preview->fresh());
    }

    private function environment(string $name): Environment
    {
        return $this->application->environments()->where('name', $name)->sole();
    }

    /** @return array<string, mixed> */
    private function pullRequest(string $action, string $sha, int $number = 12, string $headRepository = 'acme/shop'): array
    {
        return [
            'action' => $action,
            'number' => $number,
            'pull_request' => [
                'number' => $number,
                'head' => ['ref' => 'feature/login', 'sha' => $sha, 'repo' => ['full_name' => $headRepository]],
                'base' => ['ref' => 'main', 'repo' => ['full_name' => 'acme/shop']],
            ],
            'repository' => ['full_name' => 'acme/shop'],
            'installation' => ['id' => 77],
        ];
    }
}
