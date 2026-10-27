<?php

namespace App\Domains\Environments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Enums\EnvVarAvailability;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Members\Models\User;
use InvalidArgumentException;

class SetEnvVar
{
    /**
     * Creates or replaces one user value. A null value on an existing secret keeps the stored secret.
     *
     * @throws InvalidArgumentException when the key is not a valid env name, or a kept secret would become plain
     */
    public function execute(Environment $environment, User $by, string $key, ?string $value, bool $isSecret, EnvVarAvailability $availableAt): EnvVar
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
            throw new InvalidArgumentException("{$key} is not a valid env name. Use letters, digits, and underscores.");
        }

        $envVar = $environment->envVars()->firstOrNew(['key' => $key, 'source' => EnvVarSource::User]);
        $keepsSecret = $value === null && $envVar->exists && $envVar->is_secret;

        if ($keepsSecret && ! $isSecret) {
            // Nobody can read a saved secret, so it cannot turn into a plain value that the dashboard shows.
            throw new InvalidArgumentException("Give {$key} a new value to make it a plain value.");
        }

        if (! $keepsSecret) {
            $envVar->value = $value ?? '';
        }

        $envVar->is_secret = $isSecret;
        $envVar->available_at = $availableAt;
        $envVar->save();

        AuditEvent::record($by, $envVar->wasRecentlyCreated ? 'env_var.created' : 'env_var.updated', $envVar, [
            'environment_id' => $environment->id,
            'key' => $key,
            'is_secret' => $isSecret,
            'available_at' => $availableAt->value,
        ]);

        return $envVar;
    }
}
