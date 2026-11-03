<?php

namespace App\Domains\Applications\Actions;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Models\User;

class RenameApplication
{
    /**
     * Changes only the name. The domains keep their hostnames. A new preview and a new default domain take the new name.
     */
    public function execute(Application $application, User $by, string $name): void
    {
        $from = $application->name;
        $application->update(['name' => $name]);

        if (! $application->wasChanged('name')) {
            return;
        }

        AuditEvent::record($by, 'application.renamed', $application, ['from' => $from, 'to' => $name]);
    }
}
