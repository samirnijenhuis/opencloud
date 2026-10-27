<?php

namespace App\Domains\Environments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Enums\ProcessKind;
use App\Domains\Environments\Enums\Size;
use App\Domains\Environments\Models\Process;
use App\Domains\Members\Models\User;
use Illuminate\Validation\ValidationException;

class UpdateProcess
{
    /**
     * Replaces the values of a process. The running pods keep the old values until the next deploy.
     * A new name or a new kind gives a new Kubernetes object, and the next deploy deletes the old one.
     *
     * @throws ValidationException when a value breaks a rule or another process has the name
     */
    public function execute(
        Process $process,
        User $by,
        string $name,
        string $command,
        ProcessKind $kind,
        ?string $schedule,
        Size $size,
        ?int $minInstances,
        ?int $maxInstances,
        ?int $cpuTarget,
    ): Process {
        $process->fill(Process::validate($process->environment, $name, $command, $kind, $schedule, $size, $minInstances, $maxInstances, $cpuTarget, ignore: $process));

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
