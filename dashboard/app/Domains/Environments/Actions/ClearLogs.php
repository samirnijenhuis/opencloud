<?php

namespace App\Domains\Environments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Jobs\DeleteLogLines;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;

class ClearLogs
{
    /**
     * Clears the logs of every process of the environment up to now. ReadLogs shows no older line from now on, and
     * DeleteLogLines deletes the lines from the log store of the cluster.
     */
    public function execute(Environment $environment, User $by): Environment
    {
        $environment->update(['logs_cleared_at' => now()]);

        AuditEvent::record($by, 'environment.logs_cleared', $environment);
        DeleteLogLines::dispatch($environment)->afterCommit();

        return $environment;
    }
}
