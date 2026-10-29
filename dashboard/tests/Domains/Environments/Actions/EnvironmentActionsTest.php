<?php

namespace Tests\Domains\Environments\Actions;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Clusters\Enums\ClusterKind;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Deployments\Actions\StartDeployment;
use App\Domains\Deployments\Jobs\RunDeployment;
use App\Domains\Environments\Actions\DeleteEnvVar;
use App\Domains\Environments\Actions\ReadLogs;
use App\Domains\Environments\Actions\SetEnvVar;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Enums\EnvVarAvailability;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Environments\Models\Process;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Actions\AddDomain;
use App\Domains\Routing\Actions\RemoveDomain;
use App\Domains\Routing\Jobs\CheckDomain;
use App\Domains\Routing\Jobs\SyncCaddy;
use App\Domains\Routing\Models\Domain;
use App\Support\Kube\FakeKubectl;
use App\Support\Kube\KubectlResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Assert as PHPUnit;
use Tests\Fakes\FakeProcesses;
use Tests\TestCase;

class EnvironmentActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Environment $environment;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->user = User::factory()->create();
        $cluster = Cluster::factory()->create(['kind' => ClusterKind::LOCAL, 'name' => 'local']);
        $application = Application::factory()->for($cluster)->create(['repository' => 'acme/shop']);
        $this->environment = Environment::factory()->for($application)->create(['branch' => 'main']);
    }

    public function test_start_deployment_resolves_the_branch_head(): void
    {
        $processes = FakeProcesses::install();
        $processes->lsRemote = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\trefs/heads/main\n";

        $deployment = app(StartDeployment::class)->execute($this->environment, $this->user);

        $this->assertSame([['git', 'ls-remote', 'https://github.com/acme/shop.git', 'refs/heads/main']], $processes->commands());
        $this->assertSame(['GIT_TERMINAL_PROMPT' => '0'], $processes->ran[0]['env']);
        $this->assertSame('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $deployment->commit_sha);
        Queue::assertPushedOn('builds', RunDeployment::class, fn (RunDeployment $job) => $job->deployment->is($deployment));
        $this->assertSame(
            ['deployment.started', ['environment_id' => $this->environment->id, 'commit_sha' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']],
            [AuditEvent::sole()->action, AuditEvent::sole()->context],
        );
    }

    public function test_start_deployment_with_a_sha_runs_no_git_and_refuses_the_preview_template(): void
    {
        $processes = FakeProcesses::install();

        $deployment = app(StartDeployment::class)->execute($this->environment, null, 'cccccccccccccccccccccccccccccccccccccccc');

        $this->assertSame([], $processes->ran);
        $this->assertSame(['cccccccccccccccccccccccccccccccccccccccc', null], [$deployment->commit_sha, $deployment->triggered_by]);

        $template = Environment::factory()->for($this->environment->application)->create(['kind' => EnvironmentKind::PREVIEW_TEMPLATE]);
        $this->expectExceptionObject(new InvalidArgumentException('The preview template holds settings for previews. It is never deployed.'));
        app(StartDeployment::class)->execute($template, $this->user, 'cccccccccccccccccccccccccccccccccccccccc');
    }

    public function test_set_env_var_keeps_a_secret_when_the_new_value_is_null(): void
    {
        $set = app(SetEnvVar::class);

        $set->execute($this->environment, $this->user, 'STRIPE_SECRET', 'sk_live_1', true, EnvVarAvailability::RUNTIME);
        $kept = $set->execute($this->environment, $this->user, 'STRIPE_SECRET', null, true, EnvVarAvailability::BOTH);
        $set->execute($this->environment, $this->user, 'APP_NAME', 'Shop', false, EnvVarAvailability::RUNTIME);
        $set->execute($this->environment, $this->user, 'APP_NAME', 'Shop 2', false, EnvVarAvailability::RUNTIME);

        $this->assertSame(['sk_live_1', true, EnvVarAvailability::BOTH], [$kept->fresh()->value, $kept->is_secret, $kept->fresh()->available_at]);
        $this->assertSame(
            [['APP_NAME', 'Shop 2'], ['STRIPE_SECRET', 'sk_live_1']],
            $this->environment->envVars()->orderBy('key')->get()->map(fn (EnvVar $v) => [$v->key, $v->value])->all(),
        );
        $this->assertSame(['env_var.created', 'env_var.updated', 'env_var.created', 'env_var.updated'], AuditEvent::orderBy('id')->pluck('action')->all());
        $this->assertSame(
            ['environment_id' => $this->environment->id, 'key' => 'STRIPE_SECRET', 'is_secret' => true, 'available_at' => 'both'],
            AuditEvent::orderBy('id')->skip(1)->first()->context,
        );
        $this->assertStringNotContainsString('sk_live_1', AuditEvent::all()->toJson());
    }

    public function test_set_env_var_overrides_only_the_user_value_next_to_a_datastore_value(): void
    {
        EnvVar::factory()->for($this->environment)->create(['key' => 'DB_HOST', 'value' => 'mysql', 'source' => EnvVarSource::DATASTORE]);

        app(SetEnvVar::class)->execute($this->environment, $this->user, 'DB_HOST', 'my-own-db', false, EnvVarAvailability::RUNTIME);

        $this->assertSame(
            [['datastore', 'mysql'], ['user', 'my-own-db']],
            $this->environment->envVars()->orderBy('source')->get()->map(fn (EnvVar $v) => [$v->source->value, $v->value])->all(),
        );
    }

    public function test_set_env_var_refuses_to_reveal_a_kept_secret_and_bad_keys(): void
    {
        $set = app(SetEnvVar::class);
        $set->execute($this->environment, $this->user, 'TOKEN', 'abcd1234', true, EnvVarAvailability::RUNTIME);

        try {
            $set->execute($this->environment, $this->user, 'TOKEN', null, false, EnvVarAvailability::RUNTIME);
            $this->fail('A kept secret became plain.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Give TOKEN a new value to make it a plain value.', $e->getMessage());
        }

        $this->expectExceptionObject(new InvalidArgumentException('1BAD-KEY is not a valid env name. Use letters, digits, and underscores.'));
        $set->execute($this->environment, $this->user, '1BAD-KEY', 'x', false, EnvVarAvailability::RUNTIME);
    }

    public function test_delete_env_var_deletes_a_user_value_and_refuses_a_datastore_value(): void
    {
        $user = EnvVar::factory()->for($this->environment)->create(['key' => 'MAIL_HOST']);
        $datastore = EnvVar::factory()->for($this->environment)->create(['key' => 'DB_HOST', 'source' => EnvVarSource::DATASTORE]);

        app(DeleteEnvVar::class)->execute($user, $this->user);

        $this->assertSame(['DB_HOST'], $this->environment->envVars()->pluck('key')->all());
        $this->assertSame(['env_var.deleted', ['key' => 'MAIL_HOST']], [AuditEvent::sole()->action, AuditEvent::sole()->context]);

        $this->expectExceptionObject(new InvalidArgumentException('DB_HOST comes from a datastore. Detach the datastore to remove it.'));
        app(DeleteEnvVar::class)->execute($datastore, $this->user);
    }

    public function test_add_domain_normalizes_the_hostname_and_checks_dns(): void
    {
        $domain = app(AddDomain::class)->execute($this->environment, $this->user, '  WWW.Shop.Example.COM. ');

        $this->assertSame('www.shop.example.com', $domain->hostname);
        Queue::assertPushed(CheckDomain::class, fn (CheckDomain $job) => $job->domain->is($domain));
        $this->assertSame(
            ['domain.added', ['environment_id' => $this->environment->id, 'hostname' => 'www.shop.example.com']],
            [AuditEvent::sole()->action, AuditEvent::sole()->context],
        );
    }

    public function test_remove_domain_deletes_it_and_syncs_caddy(): void
    {
        $domain = Domain::factory()->for($this->environment)->create(['hostname' => 'old.example.com']);

        app(RemoveDomain::class)->execute($domain, $this->user);

        $this->assertSame(0, Domain::count());
        Queue::assertPushed(SyncCaddy::class, fn (SyncCaddy $job) => $job->cluster->is($this->environment->application->cluster));
        $this->assertSame(['domain.removed', ['hostname' => 'old.example.com']], [AuditEvent::sole()->action, AuditEvent::sole()->context]);
    }

    public function test_read_logs_asks_loki_for_one_process_of_the_environment_in_the_range_and_masks_secrets(): void
    {
        Process::factory()->for($this->environment)->create();
        EnvVar::factory()->for($this->environment)->create(['key' => 'DB_PASSWORD', 'value' => 'hunter22', 'is_secret' => true]);
        $kubectl = FakeKubectl::install();
        $kubectl->respond('exec', self::loki([
            'worker-new' => [['1793300002000000000', 'the new pod connects with hunter22']],
            'worker-old' => [['1793300001500000000', 'the old pod stops'], ['1793300000000000000', 'the old pod starts']],
        ]));

        $logs = app(ReadLogs::class)->execute($this->environment, 'worker', CarbonImmutable::parse('2026-10-28T10:00:00Z'), CarbonImmutable::parse('2026-10-29T10:00:00Z'));

        $this->assertSame(['lines' => [
            ['time' => '2026-10-29T18:53:20.000Z', 'pod' => 'worker-old', 'line' => 'the old pod starts'],
            ['time' => '2026-10-29T18:53:21.500Z', 'pod' => 'worker-old', 'line' => 'the old pod stops'],
            ['time' => '2026-10-29T18:53:22.000Z', 'pod' => 'worker-new', 'line' => 'the new pod connects with ********'],
        ], 'truncated' => false], $logs);
        $this->assertSame(['exec', 'deploy/loki', '--namespace=opencloud', '--container=query', '--', 'wget', '-q', '-O-'], array_slice($kubectl->calls[0]['args'], 0, 8));
        $this->assertSame([
            'query' => "{namespace=\"oc-env-{$this->environment->id}\", process=\"worker\"}",
            'start' => '2026-10-28T10:00:00+00:00',
            'end' => '2026-10-29T10:00:00+00:00',
            'limit' => '1000',
            'direction' => 'backward',
        ], self::lokiQuery($kubectl));

        $this->expectExceptionObject(new InvalidArgumentException('deploy is not a process of this environment. Use one of: web, worker.'));
        app(ReadLogs::class)->execute($this->environment, 'deploy', CarbonImmutable::now()->subHour(), CarbonImmutable::now());
    }

    public function test_a_search_stays_inside_the_environment_and_finds_no_line_by_a_secret(): void
    {
        EnvVar::factory()->for($this->environment)->create(['key' => 'API_TOKEN', 'value' => 'hunter22', 'is_secret' => true]);
        $kubectl = FakeKubectl::install();
        $kubectl->respond('exec', self::loki(['web-1' => [['1793300001000000000', 'hunter} logs in'], ['1793300000000000000', 'token hunter22 sent']]]));

        $logs = app(ReadLogs::class)->execute($this->environment, 'web', CarbonImmutable::now()->subHour(), CarbonImmutable::now(), 'HUNTER"}');

        $this->assertSame([], $logs['lines']);
        $this->assertSame("{namespace=\"oc-env-{$this->environment->id}\", process=\"web\"} |~ \"(?i)HUNTER\\\"\\\\}\"", self::lokiQuery($kubectl)['query']);

        $logs = app(ReadLogs::class)->execute($this->environment, 'web', CarbonImmutable::now()->subHour(), CarbonImmutable::now(), 'hunter');

        $this->assertSame(['hunter} logs in'], array_column($logs['lines'], 'line'));
    }

    public function test_read_logs_says_when_loki_gave_the_limit_of_lines(): void
    {
        FakeKubectl::install()->respond('exec', self::loki(['web-1' => array_map(fn (int $i) => [(string) (1793300000000000000 + $i), "line {$i}"], range(1, ReadLogs::LIMIT))]));

        $logs = app(ReadLogs::class)->execute($this->environment, 'web', CarbonImmutable::now()->subHour(), CarbonImmutable::now());

        $this->assertTrue($logs['truncated']);
        $this->assertSame(['line 1', 'line 1000'], [$logs['lines'][0]['line'], $logs['lines'][999]['line']]);
    }

    /** @param  array<string, list<array{0: string, 1: string}>>  $pods  the values of one stream for each pod, as Loki gives them */
    private static function loki(array $pods): KubectlResult
    {
        $streams = collect($pods)->map(fn (array $values, string $pod) => ['stream' => ['namespace' => 'oc-env-1', 'pod' => $pod, 'process' => 'web'], 'values' => $values])->values()->all();

        return new KubectlResult(0, json_encode(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => $streams]]));
    }

    /** @return array<string, string> */
    private static function lokiQuery(FakeKubectl $kubectl): array
    {
        $url = end($kubectl->calls)['args'][8];
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        PHPUnit::assertStringStartsWith('http://127.0.0.1:3100/loki/api/v1/query_range?', $url);

        return $query;
    }
}
