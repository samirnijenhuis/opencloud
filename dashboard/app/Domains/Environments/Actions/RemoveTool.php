<?php

namespace App\Domains\Environments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Tool;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Jobs\SyncCaddy;

class RemoveTool
{
    /**
     * Removes the tool. On a preview its route goes at once, and its pod keeps running until the next deploy deletes its
     * objects. The preview template routes nothing, so a removal there needs no sync.
     */
    public function execute(Tool $tool, User $by): void
    {
        $tool->delete();

        AuditEvent::record($by, 'tool.removed', $tool->environment, ['kind' => $tool->kind->value, 'hostname' => $tool->hostname]);

        if ($tool->environment->kind === EnvironmentKind::PREVIEW) {
            SyncCaddy::dispatch($tool->environment->application->cluster)->afterCommit();
        }
    }
}
