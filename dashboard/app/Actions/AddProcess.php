<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\Process;
use App\Models\User;
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
