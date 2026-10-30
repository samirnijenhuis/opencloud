<?php

namespace Tests\Domains\Previews\Actions;

use App\Domains\Applications\Actions\CreateApplication;
use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Actions\AttachDatastore;
use App\Domains\Datastores\Actions\SetDefaultAttachment;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Jobs\ProvisionDatastoreAttachment;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Deployments\Enums\DeploymentStatus;
use App\Domains\Deployments\Jobs\RunDeployment;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Actions\SetEnvVar;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Enums\EnvVarAvailability;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Enums\Size;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Environments\Models\Process;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Jobs\CheckDomain;
use App\Domains\Routing\Jobs\SyncCaddy;
use App\Domains\Routing\Models\Domain;
use App\Support\Kube\FakeKubectl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\WithGitHubApp;
use Tests\Fakes\FakeProcesses;
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
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'name' => 'local', 'ingress_ip' => '203.0.113.10']);

        $this->application = app(CreateApplication::class)->execute($owner, [
            'name' => 'Shop',
            'repository' => 'acme/shop',
            'cluster_id' => $cluster->id,
            'env' => [['key' => 'STRIPE_SECRET', 'value' => 'sk_live_production', 'is_secret' => true]],
        ]);
        app(AttachDatastore::class)->execute($this->environment('production'), $owner, DatastoreKind::VALKEY);

        $template = $this->environment('preview-template');
        $template->update(['health_path' => '/health', 'deploy_commands' => ['php artisan migrate --force', 'php artisan db:seed --force'], 'sleep_after_minutes' => 30]);
        $template->processes()->where('name', 'worker')->delete();
        Process::factory()->for($template)->create(['name' => 'horizon', 'command' => 'php artisan horizon', 'size' => Size::M, 'min_instances' => 1, 'max_instances' => 3, 'cpu_target' => 60]);
        $setEnvVar = app(SetEnvVar::class);
        $setEnvVar->execute($template, $owner, 'MAIL_MAILER', 'log', false, EnvVarAvailability::RUNTIME);
        $setEnvVar->execute($template, $owner, 'STRIPE_SECRET', 'sk_test_preview', true, EnvVarAvailability::RUNTIME);
        $setEnvVar->execute($template, $owner, 'COMPOSER_AUTH', '{"github-oauth":{"github.com":"gho_x"}}', true, EnvVarAvailability::BUILD);

        $this->makeGitHubApp();
        $this->fakeGitHub();
        AuditEvent::query()->delete();
    }

    public function test_a_pull_request_opens_redeploys_and_closes_its_preview(): void
    {
        $this->postWebhook('pull_request', $this->pullRequest('opened', self::OPENED_SHA), delivery: 'd-1')->assertStatus(202);

        $preview = $this->environment('pr-12');
        $this->assertSame(
            [EnvironmentKind::PREVIEW, 12, 'feature/login', false, 's', 1, 1, '/health', ['php artisan migrate --force', 'php artisan db:seed --force'], 30],
            [$preview->kind, $preview->pr_number, $preview->branch, $preview->is_protected, $preview->size->value, $preview->min_instances, $preview->max_instances, $preview->health_path, $preview->deploy_commands, $preview->sleep_after_minutes],
        );
        $this->assertSame(
            [
                ['scheduler', 'long_running', 'php artisan schedule:work', null, 's', 1, 1, 70],
                ['horizon', 'long_running', 'php artisan horizon', null, 'm', 1, 3, 60],
            ],
            $preview->processes->map(fn (Process $p) => [
                $p->name, $p->kind->value, $p->command, $p->schedule, $p->size->value, $p->min_instances, $p->max_instances, $p->cpu_target,
            ])->all(),
        );
        $this->assertSame(
            ['pr_number' => 12, 'branch' => 'feature/login', 'processes' => ['scheduler', 'horizon']],
            AuditEvent::where('action', 'preview.created')->sole()->context,
        );

        $userEnv = $preview->envVars()->where('source', EnvVarSource::USER)->orderBy('key')->get()
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
            [[self::OPENED_SHA, DeploymentStatus::QUEUED, null], [self::PUSHED_SHA, DeploymentStatus::QUEUED, null]],
            $preview->deployments()->orderBy('id')->get()->map(fn (Deployment $d) => [$d->commit_sha, $d->status, $d->triggered_by])->all(),
        );
        Queue::assertPushed(RunDeployment::class, 3);
        $this->assertSame(1, Environment::query()->where('kind', EnvironmentKind::PREVIEW)->count());

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
        $reports = app(AttachDatastore::class)->execute($production, $owner, DatastoreKind::POSTGRES, name: 'reports');
        app(SetDefaultAttachment::class)->execute($reports, $owner);

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

    public function test_a_preview_gets_a_local_database_for_a_managed_database_of_production(): void
    {
        $production = $this->environment('production');
        $managed = Datastore::factory()->create(['cluster_id' => $production->application->cluster_id, 'kind' => DatastoreKind::POSTGRES, 'source' => DatastoreSource::MANAGED]);
        DatastoreAttachment::factory()->create(['datastore_id' => $managed->id, 'environment_id' => $production->id, 'name' => 'reports', 'is_default' => false]);

        $this->postWebhook('pull_request', $this->pullRequest('opened', self::OPENED_SHA), delivery: 'd-1')->assertStatus(202);

        $preview = $this->environment('pr-12');
        $this->assertSame(
            [['postgres', 'reports', DatastoreSource::STANDARD]],
            $preview->datastoreAttachments()->with('datastore')->get()
                ->filter(fn (DatastoreAttachment $a) => $a->datastore->kind === DatastoreKind::POSTGRES)
                ->map(fn (DatastoreAttachment $a) => [$a->datastore->kind->value, $a->name, $a->datastore->source])->values()->all(),
        );
        $this->assertNotSame($managed->id, $preview->datastoreAttachments()->where('name', 'reports')->sole()->datastore_id);
    }

    public function test_a_preview_domain_gives_each_preview_a_subdomain(): void
    {
        $this->application->update(['preview_domain' => 'preview.example.com']);

        $this->postWebhook('pull_request', $this->pullRequest('opened', self::OPENED_SHA))->assertStatus(202);

        $this->assertSame(['pr-12.preview.example.com'], $this->environment('pr-12')->domains()->pluck('hostname')->all());
    }

    public function test_a_pull_request_from_a_fork_gets_no_preview(): void
    {
        $fork = $this->pullRequest('opened', self::OPENED_SHA, headRepository: 'mallory/shop');

        $this->postWebhook('pull_request', $fork, delivery: 'd-1')->assertStatus(202);

        $this->assertSame(0, Environment::query()->where('kind', EnvironmentKind::PREVIEW)->count());
        $this->assertSame(['preview.skipped', ['pr_number' => 12, 'reason' => 'fork']], [AuditEvent::sole()->action, AuditEvent::sole()->context]);
    }

    public function test_switched_off_previews_create_nothing(): void
    {
        $this->application->update(['previews_enabled' => false]);

        $this->postWebhook('pull_request', $this->pullRequest('opened', self::OPENED_SHA))->assertStatus(202);

        $this->assertSame(0, Environment::query()->where('kind', EnvironmentKind::PREVIEW)->count());
        $this->assertSame(['pr_number' => 12, 'reason' => 'previews_disabled'], AuditEvent::sole()->context);
    }

    public function test_over_the_limit_a_comment_says_so_and_nothing_is_created(): void
    {
        config(['opencloud.max_previews' => 1]);
        $this->fakeGitHub(more: ['api.github.com/repos/acme/shop/issues/13/comments' => Http::response(['id' => 9001], 201)]);
        $this->postWebhook('pull_request', $this->pullRequest('opened', self::OPENED_SHA), delivery: 'd-1');

        $this->postWebhook('pull_request', $this->pullRequest('opened', self::OPENED_SHA, number: 13), delivery: 'd-2')->assertStatus(202);
        $this->postWebhook('pull_request', $this->pullRequest('synchronize', self::PUSHED_SHA, number: 13), delivery: 'd-3')->assertStatus(202);

        $this->assertSame(['pr-12'], Environment::query()->where('kind', EnvironmentKind::PREVIEW)->pluck('name')->all());
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
        $preview->deployments()->sole()->moveTo(DeploymentStatus::BUILDING);

        $this->postWebhook('pull_request', $this->pullRequest('closed', self::OPENED_SHA), delivery: 'd-2')->assertStatus(202);

        $this->assertSame([], $this->kubectl->calls);
        $this->assertNotNull($preview->fresh());
    }

    public function test_a_placeholder_in_a_template_value_gives_each_preview_its_own_value(): void
    {
        $owner = User::factory()->owner()->create();
        $template = $this->environment('preview-template');
        app(SetEnvVar::class)->execute($template, $owner, 'APP_NAME', 'Shop PR {pr_number}', false, EnvVarAvailability::RUNTIME);
        app(SetEnvVar::class)->execute($template, $owner, 'PREVIEW_LINK', '{url}/?branch={branch}', true, EnvVarAvailability::BUILD);

        $this->postWebhook('pull_request', $this->pullRequest('opened', self::OPENED_SHA), delivery: 'd-1')->assertStatus(202);
        $this->postWebhook('pull_request', $this->pullRequest('reopened', self::OPENED_SHA, number: 13, branch: 'fix/cart'), delivery: 'd-2')->assertStatus(202);

        $values = fn (string $environment) => $this->environment($environment)->envVars()->whereIn('key', ['APP_NAME', 'PREVIEW_LINK'])->orderBy('key')->get()
            ->map(fn (EnvVar $var) => [$var->key, $var->value, $var->is_secret, $var->available_at->value])->all();
        $this->assertSame([
            ['APP_NAME', 'Shop PR 12', false, 'runtime'],
            ['PREVIEW_LINK', 'https://pr-12-shop.203.0.113.10.sslip.io/?branch=feature/login', true, 'build'],
        ], $values('pr-12'));
        $this->assertSame([
            ['APP_NAME', 'Shop PR 13', false, 'runtime'],
            ['PREVIEW_LINK', 'https://pr-13-shop.203.0.113.10.sslip.io/?branch=fix/cart', true, 'build'],
        ], $values('pr-13'));
        $this->assertSame([
            ['APP_NAME', 'Shop PR {pr_number}', false, 'runtime'],
            ['PREVIEW_LINK', '{url}/?branch={branch}', true, 'build'],
        ], $values('preview-template'));
    }

    public function test_a_change_to_the_env_of_one_preview_survives_a_push(): void
    {
        $this->postWebhook('pull_request', $this->pullRequest('opened', self::OPENED_SHA), delivery: 'd-1');
        $preview = $this->environment('pr-12');
        app(SetEnvVar::class)->execute($preview, User::factory()->owner()->create(), 'MAIL_MAILER', 'smtp', false, EnvVarAvailability::RUNTIME);

        $this->postWebhook('pull_request', $this->pullRequest('synchronize', self::PUSHED_SHA), delivery: 'd-2')->assertStatus(202);

        $this->assertSame([self::OPENED_SHA, self::PUSHED_SHA], $preview->deployments()->orderBy('id')->pluck('commit_sha')->all());
        $this->assertSame('smtp', $preview->envVars()->where('key', 'MAIL_MAILER')->sole()->value);
        $this->assertSame('log', $this->environment('preview-template')->envVars()->where('key', 'MAIL_MAILER')->sole()->value);
    }

    public function test_with_a_label_filter_a_pull_request_has_a_preview_only_while_it_has_one_of_the_labels(): void
    {
        $this->application->update(['preview_labels' => ['Preview', 'deploy-me']]);

        $this->postWebhook('pull_request', $this->pullRequest('opened', self::OPENED_SHA), delivery: 'd-1')->assertStatus(202);
        $this->postWebhook('pull_request', $this->pullRequest('synchronize', self::PUSHED_SHA), delivery: 'd-2')->assertStatus(202);

        $this->assertSame(0, Environment::query()->where('kind', EnvironmentKind::PREVIEW)->count());

        $this->postWebhook('pull_request', $this->pullRequest('labeled', self::PUSHED_SHA, labels: ['preview'], label: 'preview'), delivery: 'd-3')->assertStatus(202);

        $preview = $this->environment('pr-12');
        $this->assertSame([self::PUSHED_SHA], $preview->deployments()->pluck('commit_sha')->all());

        $this->postWebhook('pull_request', $this->pullRequest('labeled', self::PUSHED_SHA, labels: ['preview', 'bug'], label: 'bug'), delivery: 'd-4')->assertStatus(202);
        $this->postWebhook('pull_request', $this->pullRequest('unlabeled', self::PUSHED_SHA, labels: ['preview'], label: 'bug'), delivery: 'd-5')->assertStatus(202);

        $this->assertSame([self::PUSHED_SHA], $preview->deployments()->pluck('commit_sha')->all());
        $this->assertSame([], $this->kubectl->calls);

        $this->postWebhook('pull_request', $this->pullRequest('unlabeled', self::PUSHED_SHA, labels: [], label: 'preview'), delivery: 'd-6')->assertStatus(202);

        $this->assertNull($preview->fresh());
        $this->assertSame(['delete', 'namespace', "oc-env-{$preview->id}", '--ignore-not-found', '--wait=false'], $this->kubectl->calls[0]['args']);
        $this->assertSame(
            ['environment_id' => $preview->id, 'pr_number' => 12, 'namespace' => "oc-env-{$preview->id}"],
            AuditEvent::query()->where('action', 'preview.destroyed')->sole()->context,
        );
    }

    public function test_a_label_change_on_a_closed_pull_request_opens_no_preview(): void
    {
        $this->application->update(['preview_labels' => ['preview']]);
        $this->postWebhook('pull_request', $this->pullRequest('labeled', self::OPENED_SHA, labels: ['preview'], label: 'preview'), delivery: 'd-1');
        $this->postWebhook('pull_request', $this->pullRequest('closed', self::OPENED_SHA, labels: ['preview']), delivery: 'd-2');

        $this->postWebhook('pull_request', $this->pullRequest('unlabeled', self::OPENED_SHA, labels: [], label: 'preview', closed: true), delivery: 'd-3')->assertStatus(202);
        $this->postWebhook('pull_request', $this->pullRequest('labeled', self::OPENED_SHA, labels: ['preview'], label: 'preview', closed: true), delivery: 'd-4')->assertStatus(202);

        $this->assertSame(0, Environment::query()->where('kind', EnvironmentKind::PREVIEW)->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'preview.created')->count());
    }

    public function test_a_close_that_waits_holds_back_the_previews_that_the_same_event_opens(): void
    {
        $staging = app(CreateApplication::class)->execute(User::factory()->owner()->create(), [
            'name' => 'Shop staging',
            'repository' => 'acme/shop',
            'cluster_id' => $this->application->cluster_id,
        ]);
        $staging->update(['preview_labels' => ['preview']]);
        $this->postWebhook('pull_request', $this->pullRequest('opened', self::OPENED_SHA, labels: ['preview']), delivery: 'd-1');
        $stagingPreview = $staging->environments()->where('name', 'pr-12')->sole();
        $stagingPreview->deployments()->sole()->moveTo(DeploymentStatus::BUILDING);

        $this->postWebhook('pull_request', $this->pullRequest('synchronize', self::PUSHED_SHA), delivery: 'd-2')->assertStatus(202);

        $this->assertSame([self::OPENED_SHA], $this->environment('pr-12')->deployments()->pluck('commit_sha')->all());
        $this->assertNotNull($stagingPreview->fresh());

        $stagingPreview->deployments()->sole()->moveTo(DeploymentStatus::FAILED);
        $this->postWebhook('pull_request', $this->pullRequest('synchronize', self::PUSHED_SHA), delivery: 'd-3')->assertStatus(202);

        $this->assertSame([self::OPENED_SHA, self::PUSHED_SHA], $this->environment('pr-12')->deployments()->orderBy('id')->pluck('commit_sha')->all());
        $this->assertNull($stagingPreview->fresh());
    }

    public function test_without_a_label_filter_a_label_change_leaves_the_previews_as_they_are(): void
    {
        $this->postWebhook('pull_request', $this->pullRequest('opened', self::OPENED_SHA), delivery: 'd-1');
        $events = AuditEvent::query()->count();

        $this->postWebhook('pull_request', $this->pullRequest('labeled', self::OPENED_SHA, labels: ['bug'], label: 'bug'), delivery: 'd-2')->assertStatus(202);
        $this->postWebhook('pull_request', $this->pullRequest('unlabeled', self::OPENED_SHA, labels: [], label: 'bug'), delivery: 'd-3')->assertStatus(202);
        $this->postWebhook('pull_request', $this->pullRequest('labeled', self::OPENED_SHA, number: 13, labels: ['bug'], label: 'bug'), delivery: 'd-4')->assertStatus(202);

        $this->assertSame(['pr-12'], Environment::query()->where('kind', EnvironmentKind::PREVIEW)->pluck('name')->all());
        $this->assertSame([self::OPENED_SHA], $this->environment('pr-12')->deployments()->pluck('commit_sha')->all());
        $this->assertSame([], $this->kubectl->calls);
        $this->assertSame($events, AuditEvent::query()->count());
    }

    private function environment(string $name): Environment
    {
        return $this->application->environments()->where('name', $name)->sole();
    }

    /**
     * @param  list<string>  $labels  the labels of the pull request after the action
     * @param  string|null  $label  the label that a "labeled" or "unlabeled" action adds or removes
     * @param  bool  $closed  whether the pull request is closed, which a "closed" action always is
     * @return array<string, mixed>
     */
    private function pullRequest(string $action, string $sha, int $number = 12, string $headRepository = 'acme/shop', string $branch = 'feature/login', array $labels = [], ?string $label = null, bool $closed = false): array
    {
        return [
            'action' => $action,
            'number' => $number,
            'pull_request' => [
                'number' => $number,
                'state' => $closed || $action === 'closed' ? 'closed' : 'open',
                'head' => ['ref' => $branch, 'sha' => $sha, 'repo' => ['full_name' => $headRepository]],
                'base' => ['ref' => 'main', 'repo' => ['full_name' => 'acme/shop']],
                'labels' => array_map(fn (string $name) => ['name' => $name, 'color' => 'ededed'], $labels),
            ],
            ...($label === null ? [] : ['label' => ['name' => $label, 'color' => 'ededed']]),
            'repository' => ['full_name' => 'acme/shop'],
            'installation' => ['id' => 77],
        ];
    }
}
