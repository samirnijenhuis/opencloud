<?php

namespace App\Actions;

use App\Enums\DeploymentStatus;
use App\Jobs\ExecuteCommandRun;
use App\Models\AuditEvent;
use App\Models\CommandRun;
use App\Models\Environment;
use App\Models\User;
use App\Support\SecretMasker;
use Illuminate\Validation\ValidationException;

class RunCommand
{
    /**
     * Stores the run and queues it. The command runs with the image and the env of the live deployment.
     *
     * @throws ValidationException when the command is empty or the environment has no live deployment
     */
    public function handle(Environment $environment, User $by, string $command): CommandRun
    {
        $command = trim($command);

        if ($command === '') {
            throw ValidationException::withMessages(['command' => 'Enter a command.']);
        }

        $live = $environment->deployments()->where('status', DeploymentStatus::Live)->first();

        if ($live === null) {
            throw ValidationException::withMessages(['command' => 'The environment has no live deployment. Deploy it first.']);
        }

        $run = $environment->commandRuns()->create(['user_id' => $by->id, 'deployment_id' => $live->id, 'command' => $command]);

        AuditEvent::record($by, 'command.started', $run, [
            'environment_id' => $environment->id,
            'command' => SecretMasker::forEnvironment($environment)->mask($command),
        ]);
        ExecuteCommandRun::dispatch($run)->afterCommit();

        return $run;
    }
}
