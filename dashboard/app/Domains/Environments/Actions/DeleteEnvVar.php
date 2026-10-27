<?php

namespace App\Domains\Environments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Members\Models\User;
use InvalidArgumentException;

class DeleteEnvVar
{
    /** @throws InvalidArgumentException for a datastore value, which leaves with its datastore */
    public function handle(EnvVar $envVar, User $by): void
    {
        if ($envVar->source !== EnvVarSource::User) {
            throw new InvalidArgumentException("{$envVar->key} comes from a datastore. Detach the datastore to remove it.");
        }

        $envVar->delete();

        AuditEvent::record($by, 'env_var.deleted', $envVar->environment, ['key' => $envVar->key]);
    }
}
