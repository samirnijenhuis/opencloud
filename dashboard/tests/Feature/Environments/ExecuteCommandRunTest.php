<?php

namespace Tests\Feature\Environments;

use App\Enums\ApplicationType;
use App\Enums\ClusterKind;
use App\Enums\DeploymentStatus;
use App\Environments\CommandRunStatus;
use App\Jobs\ExecuteCommandRun;
use App\Kube\FakeKubectl;
use App\Kube\KubectlException;
use App\Kube\KubectlResult;
use App\Models\Application;
use App\Models\Cluster;
use App\Models\CommandRun;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\EnvVar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExecuteCommandRunTest extends TestCase
{
    use RefreshDatabase;

    private const APPLY = ['apply', '--server-side', '--force-conflicts', '--field-manager=opencloud', '-f', '-'];

    private FakeKubectl $kubectl;

    private Environment $environment;

    private CommandRun $run;

    private string $namespace;

    private string $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-24 12:00:00'));
        $this->kubectl = FakeKubectl::install();

        $cluster = Cluster::factory()->create(['kind' => ClusterKind::Local, 'name' => 'local']);
        $application = Application::factory()->for($cluster)->create(['type' => ApplicationType::Laravel]);
        $this->environment = Environment::factory()->for($application)->create(['name' => 'production']);
        EnvVar::factory()->for($this->environment)->create(['key' => 'DB_PASSWORD', 'value' => 'hunter22', 'is_secret' => true]);
        $live = Deployment::factory()->for($this->environment)->create(['status' => DeploymentStatus::Live, 'image' => 'registry.example/app:abc1234-5']);
        $this->run = CommandRun::create([
            'environment_id' => $this->environment->id,
            'user_id' => User::factory()->owner()->create()->id,
            'deployment_id' => $live->id,
            'command' => 'php artisan --version',
        ]);
        $this->namespace = "oc-env-{$this->environment->id}";
        $this->job = "cmd-{$this->run->id}";
    }

    public function test_a_command_that_ends_well_succeeds_with_exit_code_zero_and_its_masked_output(): void
    {
        $this->jobState([]);
        $this->logs("Laravel Framework 12.0.1\n");

        ExecuteCommandRun::dispatchSync($this->run);

        $running = $this->run->fresh();
        $this->assertSame([CommandRunStatus::Running, null, "Laravel Framework 12.0.1\n", '2026-10-24 12:00:00', null], [
            $running->status, $running->exit_code, $running->output, $running->started_at->toDateTimeString(), $running->finished_at,
        ]);

        $this->travel(4)->seconds();
        $this->jobState([['type' => 'SuccessCriteriaMet', 'status' => 'True'], ['type' => 'Complete', 'status' => 'True']]);
        $this->logs("\e[32mINFO\e[39m Laravel Framework 12.0.1\nconnected with hunter22\n");

        ExecuteCommandRun::dispatchSync($running);

        $done = $this->run->fresh();
        $this->assertSame([CommandRunStatus::Succeeded, 0, "INFO Laravel Framework 12.0.1\nconnected with ********\n", '2026-10-24 12:00:04'], [
            $done->status, $done->exit_code, $done->output, $done->finished_at->toDateTimeString(),
        ]);
        $this->assertSame([
            self::APPLY,
            ['get', 'job', $this->job, "--namespace={$this->namespace}", '--ignore-not-found', '-o', 'json'],
            ['logs', "job/{$this->job}", "--namespace={$this->namespace}"],
            ['get', 'job', $this->job, "--namespace={$this->namespace}", '--ignore-not-found', '-o', 'json'],
            ['logs', "job/{$this->job}", "--namespace={$this->namespace}"],
        ], array_column($this->kubectl->calls, 'args'));
        $this->assertSame(['Job'], array_column($this->kubectl->appliedManifests(), 'kind'));
        $this->assertSame(['sh', '-c', 'php artisan --version'], $this->kubectl->appliedManifests()[0]['spec']['template']['spec']['containers'][0]['command']);

        ExecuteCommandRun::dispatchSync($done);
        $this->assertCount(5, $this->kubectl->calls, 'A finished run makes no more calls.');
    }

    public function test_a_command_that_exits_with_an_error_fails_with_its_exit_code(): void
    {
        $this->jobState([['type' => 'Failed', 'status' => 'True', 'reason' => 'BackoffLimitExceeded']]);
        $this->logs("In Connection.php line 825:\n  could not find driver\n");
        $this->kubectl->respond("get pods --selector=job-name={$this->job}", new KubectlResult(0, json_encode(['items' => [[
            'status' => ['phase' => 'Failed', 'containerStatuses' => [['name' => 'command', 'state' => ['terminated' => ['exitCode' => 3, 'reason' => 'Error']]]]],
        ]]])));

        ExecuteCommandRun::dispatchSync($this->run);

        $run = $this->run->fresh();
        $this->assertSame([CommandRunStatus::Failed, 3, "In Connection.php line 825:\n  could not find driver\n"], [$run->status, $run->exit_code, $run->output]);
        $this->assertSame(
            ['get', 'pods', "--selector=job-name={$this->job}", "--namespace={$this->namespace}", '--ignore-not-found', '-o', 'json'],
            $this->kubectl->callsStartingWith('get pods')[0]['args'],
        );
    }

    public function test_a_command_past_the_kubernetes_deadline_times_out_and_keeps_the_output_it_printed(): void
    {
        $this->jobState([]);
        $this->logs("Importing 1 of 900\n");
        ExecuteCommandRun::dispatchSync($this->run);

        $this->jobState([['type' => 'Failed', 'status' => 'True', 'reason' => 'DeadlineExceeded']]);
        $this->kubectl->respond("logs job/{$this->job}", new KubectlResult(1, '', 'error: timed out waiting for the condition'));
        ExecuteCommandRun::dispatchSync($this->run->fresh());

        $run = $this->run->fresh();
        $this->assertSame([CommandRunStatus::TimedOut, null, "Importing 1 of 900\n"], [$run->status, $run->exit_code, $run->output]);
    }

    public function test_a_job_that_the_cluster_never_ends_times_out_after_the_deadline_and_is_deleted(): void
    {
        $this->jobState([]);
        $this->logs('');
        ExecuteCommandRun::dispatchSync($this->run);

        $this->travel(960)->seconds();
        ExecuteCommandRun::dispatchSync($this->run->fresh());
        $this->assertSame(CommandRunStatus::Running, $this->run->fresh()->status);
        $this->assertSame([], $this->kubectl->callsStartingWith('delete'));

        $this->travel(1)->seconds();
        ExecuteCommandRun::dispatchSync($this->run->fresh());

        $this->assertSame(CommandRunStatus::TimedOut, $this->run->fresh()->status);
        $this->assertSame(
            [['delete', 'job', $this->job, "--namespace={$this->namespace}", '--ignore-not-found', '--wait=false']],
            array_column($this->kubectl->callsStartingWith('delete'), 'args'),
        );
    }

    public function test_a_running_command_whose_job_is_gone_fails_with_a_message(): void
    {
        $this->run->update(['status' => CommandRunStatus::Running, 'started_at' => now(), 'output' => "Starting\n"]);

        ExecuteCommandRun::dispatchSync($this->run->fresh());

        $run = $this->run->fresh();
        $this->assertSame([CommandRunStatus::Failed, null, "Starting\nThe Kubernetes Job of the command is gone."], [$run->status, $run->exit_code, $run->output]);
        $this->assertSame([], $this->kubectl->callsStartingWith('apply'));
    }

    public function test_a_job_that_cannot_start_fails_the_run_with_the_reason(): void
    {
        $this->kubectl->respond('apply', new KubectlResult(1, '', 'Error from server (NotFound): namespaces "oc-env-9" not found'));

        try {
            ExecuteCommandRun::dispatchSync($this->run);
            $this->fail('The job should throw.');
        } catch (KubectlException) {
        }

        $run = $this->run->fresh();
        $this->assertSame(
            [CommandRunStatus::Failed, 'Opencloud could not run the command. Error from server (NotFound): namespaces "oc-env-9" not found'],
            [$run->status, $run->output],
        );
    }

    /** @param  list<array<string, string>>  $conditions */
    private function jobState(array $conditions): void
    {
        $this->kubectl->respond("get job {$this->job}", new KubectlResult(0, json_encode(['kind' => 'Job', 'status' => ['conditions' => $conditions]])));
    }

    private function logs(string $output): void
    {
        $this->kubectl->respond("logs job/{$this->job}", new KubectlResult(0, $output));
    }
}
