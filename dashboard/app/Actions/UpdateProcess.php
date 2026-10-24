<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\Process;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class UpdateProcess
{
    /**
     * Replaces the values of a process. The running pods keep the old values until the next deploy.
     * A new name gives a new Kubernetes Deployment, and the next deploy deletes the old one.
     *
     * @throws ValidationException when a value breaks a rule or another process has the name
     */
    public function handle(Process $process, User $by, string $name, string $command, int $instances, bool $exclusive): Process
    {
        $process->fill(Process::validate($process->environment, $name, $command, $instances, $exclusive, ignore: $process));

        $changes = [];

        foreach ($process->getDirty() as $field => $to) {
            $changes[$field] = ['from' => $process->getOriginal($field), 'to' => $process->getAttribute($field)];
        }

        if ($changes === []) {
            return $process;
        }

        $process->save();

        AuditEvent::record($by, 'process.updated', $process, ['environment_id' => $process->environment_id, 'changes' => $changes]);

        return $process;
    }
}
