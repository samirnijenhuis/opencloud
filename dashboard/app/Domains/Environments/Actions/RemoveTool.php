<?php

namespace App\Domains\Environments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Models\Tool;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Jobs\SyncCaddy;

class RemoveTool
{
    /** Removes the tool and its route at once. Its pod keeps running until the next deploy deletes its objects. */
    public function execute(Tool $tool, User $by): void
    {
        $tool->delete();

        AuditEvent::record($by, 'tool.removed', $tool->environment, ['kind' => $tool->kind->value, 'hostname' => $tool->hostname]);
        SyncCaddy::dispatch($tool->environment->application->cluster)->afterCommit();
    }
}
