<?php

namespace App\Domains\Environments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\Process;
use App\Domains\Members\Models\User;
use Illuminate\Validation\ValidationException;

class AddProcess
{
    /**
     * Adds a process to the environment. It starts on the next deploy.
     *
     * @throws ValidationException when a value breaks a rule or another process has the name
     */
    public function handle(Environment $environment, User $by, string $name, string $command, int $instances = 1, bool $exclusive = false): Process
    {
        $attributes = Process::validate($environment, $name, $command, $instances, $exclusive);
        $process = $environment->processes()->create($attributes);

        AuditEvent::record($by, 'process.added', $process, ['environment_id' => $environment->id, ...$attributes]);

        return $process;
    }
}
