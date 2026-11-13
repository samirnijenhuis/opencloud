<?php

namespace App\Domains\Environments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Tool;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Jobs\SyncCaddy;

class UpdateTool
{
    /**
     * Changes the hostname of the tool. On a preview, Caddy routes the new hostname at once, so the tool needs no deploy.
     * The preview template routes nothing, so a change there needs no sync.
     */
    public function execute(Tool $tool, User $by, string $hostname): Tool
    {
        $from = $tool->hostname;

        if ($from === $hostname) {
            return $tool;
        }

        $tool->update(['hostname' => $hostname]);

        AuditEvent::record($by, 'tool.updated', $tool, ['environment_id' => $tool->environment_id, 'kind' => $tool->kind->value, 'from' => $from, 'to' => $hostname]);

        if ($tool->environment->kind === EnvironmentKind::PREVIEW) {
            SyncCaddy::dispatch($tool->environment->application->cluster)->afterCommit();
        }

        return $tool;
    }
}
