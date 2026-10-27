<?php

namespace App\Domains\Environments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Models\Process;
use App\Domains\Members\Models\User;

class RemoveProcess
{
    /** Removes the process. Its pods keep running until the next deploy deletes its Kubernetes Deployment. */
    public function handle(Process $process, User $by): void
    {
        $process->delete();

        AuditEvent::record($by, 'process.removed', $process->environment, ['name' => $process->name]);
    }
}
