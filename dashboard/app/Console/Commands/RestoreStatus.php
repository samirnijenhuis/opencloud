<?php

namespace App\Console\Commands;

use App\Models\Deployment;
use App\Restore\RestoreState;
use App\Restore\RestoreStep;
use App\Restore\StepStatus;
use Illuminate\Console\Command;

class RestoreStatus extends Command
{
    protected $signature = 'opencloud:restore-status';

    protected $description = 'Show the progress of the restore';

    public function handle(): int
    {
        $state = RestoreState::load();

        if ($state === null) {
            $this->line('No restore on this server.');

            return self::SUCCESS;
        }

        $this->line("The restore started at {$state->startedAt->toDateTimeString()} UTC.");

        foreach (RestoreStep::cases() as $step) {
            $this->line(rtrim(sprintf('%-8s %-27s %s', $state->status($step)->value, $step->label(), $state->detail($step) ?? '')));
        }

        $deployments = Deployment::query()
            ->with('environment.application')
            ->whereIn('environment_id', array_keys($state->redeploy() ?? []))
            ->where('created_at', '>=', $state->startedAt)
            ->orderBy('id')
            ->get();

        foreach ($deployments as $deployment) {
            $this->line("Deployment {$deployment->id} of {$deployment->environment->application->name}/{$deployment->environment->name}: {$deployment->status->value}");
        }

        if (collect(RestoreStep::cases())->contains(fn (RestoreStep $step) => $state->status($step) === StepStatus::Failed)) {
            $this->line('A step failed. Fix the cause, then run: opencloud restore');

            return self::FAILURE;
        }

        $this->line($state->finished() ? 'The restore is done.' : 'The restore is running.');

        return self::SUCCESS;
    }
}
