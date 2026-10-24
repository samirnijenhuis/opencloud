<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\Process;
use App\Models\User;

class RemoveProcess
{
    /** Removes the process. Its pods keep running until the next deploy deletes its Kubernetes Deployment. */
    public function handle(Process $process, User $by): void
    {
        $process->delete();

        AuditEvent::record($by, 'process.removed', $process->environment, ['name' => $process->name]);
    }
}
