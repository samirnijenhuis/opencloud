<?php

namespace App\Domains\Environments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Enums\ToolKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\Tool;
use App\Domains\Members\Models\User;

class AddTool
{
    /**
     * Adds a tool to a preview or to the preview template. On a preview it starts on the next deploy, as a new process
     * does. On the template, each new preview gets a copy.
     */
    public function execute(Environment $environment, User $by, ToolKind $kind, string $hostname): Tool
    {
        $tool = $environment->tools()->create(['kind' => $kind, 'hostname' => $hostname]);

        AuditEvent::record($by, 'tool.added', $tool, ['environment_id' => $environment->id, 'kind' => $kind->value, 'hostname' => $hostname]);

        return $tool;
    }
}
