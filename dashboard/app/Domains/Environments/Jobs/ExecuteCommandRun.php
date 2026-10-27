<?php

namespace App\Domains\Environments\Jobs;

use App\Domains\Environments\CommandManifests;
use App\Domains\Environments\Enums\CommandRunStatus;
use App\Domains\Environments\Models\CommandRun;
use App\Support\Kube\Kubectl;
use App\Support\SecretMasker;
use App\Support\TerminalText;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Starts the Kubernetes Job of a command run, then checks it every few seconds until it ends.
 * Each check releases this job back to the queue, so a long command never blocks the default queue.
 */
class ExecuteCommandRun implements ShouldQueue
{
    use Queueable;

    private const CHECK_SECONDS = 3;

    /** Each check reads the output again, so a chatty command must not grow it without end. */
    public const OUTPUT_LINES = 2000;

    /** Kubernetes stops the Job at its deadline. The margin covers a cluster that does not report it. */
    private const DEADLINE_SECONDS = CommandManifests::DEADLINE_SECONDS + 60;

    public int $maxExceptions = 3;

    public int $backoff = 5;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public CommandRun $run) {}

    /** The checks release the job, so tries do not limit it. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(): void
    {
        $run = $this->run;

        if ($run->status->finished()) {
            return;
        }

        $kubectl = Kubectl::for($run->environment->application->cluster);

        // Apply comes before the move, so a retry after a crash applies the same Job again and changes nothing.
        if ($run->status === CommandRunStatus::Queued) {
            $kubectl->apply([(new CommandManifests($run))->job()]);
            $run->started_at = now();
            $run->moveTo(CommandRunStatus::Running);
        }

        $this->check($kubectl, $run);

        if (! $run->status->finished()) {
            $this->release(self::CHECK_SECONDS);
        }
    }

    public function failed(?Throwable $e): void
    {
        $run = $this->run->fresh();

        if ($run !== null && ! $run->status->finished()) {
            $this->finish($run, CommandRunStatus::Failed, null, trim('Opencloud could not run the command. '.$e?->getMessage()));
        }
    }

    private function check(Kubectl $kubectl, CommandRun $run): void
    {
        $namespace = $run->environment->kubeNamespace();
        $name = CommandManifests::jobName($run);
        $job = $kubectl->get('job', $name, $namespace);

        if ($job === null) {
            $this->finish($run, CommandRunStatus::Failed, null, 'The Kubernetes Job of the command is gone.');

            return;
        }

        // The state comes first, so the logs that follow a finished state are complete.
        $outcome = self::outcome($job);
        $this->copyOutput($kubectl, $run, $namespace, $name);

        if ($outcome === null) {
            if (now()->subSeconds(self::DEADLINE_SECONDS)->greaterThan($run->started_at)) {
                $kubectl->delete('job', $name, $namespace);
                $this->finish($run, CommandRunStatus::TimedOut, null);
            }

            return;
        }

        $this->finish($run, $outcome, match ($outcome) {
            CommandRunStatus::Succeeded => 0,
            CommandRunStatus::Failed => $this->exitCode($kubectl, $namespace, $name),
            default => null,
        });
    }

    /**
     * @param  array<string, mixed>  $job
     * @return CommandRunStatus|null null while the Job runs
     */
    private static function outcome(array $job): ?CommandRunStatus
    {
        foreach ($job['status']['conditions'] ?? [] as $condition) {
            if (($condition['status'] ?? null) !== 'True') {
                continue;
            }

            if ($condition['type'] === 'Complete') {
                return CommandRunStatus::Succeeded;
            }

            if ($condition['type'] === 'Failed') {
                return ($condition['reason'] ?? null) === 'DeadlineExceeded' ? CommandRunStatus::TimedOut : CommandRunStatus::Failed;
            }
        }

        return null;
    }

    private function exitCode(Kubectl $kubectl, string $namespace, string $name): ?int
    {
        $pods = $kubectl->get('pods', namespace: $namespace, selector: ['job-name' => $name])['items'] ?? [];
        $code = $pods[0]['status']['containerStatuses'][0]['state']['terminated']['exitCode'] ?? null;

        return $code === null ? null : (int) $code;
    }

    /** The logs exist only after the container starts, so a failed read keeps the output that the run has. */
    private function copyOutput(Kubectl $kubectl, CommandRun $run, string $namespace, string $name): void
    {
        $result = $kubectl->run(['logs', "job/{$name}", "--namespace={$namespace}", '--tail='.self::OUTPUT_LINES]);

        if (! $result->ok()) {
            return;
        }

        $output = TerminalText::plain($result->output);

        if (substr_count($output, "\n") >= self::OUTPUT_LINES) {
            $output = 'Opencloud keeps the last '.self::OUTPUT_LINES." lines of the output.\n{$output}";
        }

        $run->output = SecretMasker::forEnvironment($run->environment)->mask($output);
        $run->save();
    }

    private function finish(CommandRun $run, CommandRunStatus $status, ?int $exitCode, ?string $message = null): void
    {
        if ($message !== null) {
            $run->output = ltrim(rtrim($run->output ?? '')."\n".$message);
        }

        $run->exit_code = $exitCode;
        $run->finished_at = now();
        $run->moveTo($status);
    }
}
