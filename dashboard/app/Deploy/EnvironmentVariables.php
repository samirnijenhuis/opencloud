<?php

namespace App\Deploy;

use App\Enums\EnvVarAvailability;
use App\Enums\EnvVarSource;
use App\Models\Environment;

/**
 * Merges the env of an environment. A user value beats a datastore value, and both beat the system defaults.
 */
final class EnvironmentVariables
{
    /** @return array<string, string> sorted by key */
    public static function runtime(Environment $environment): array
    {
        return self::merge(self::systemDefaults($environment), $environment, [EnvVarAvailability::Runtime, EnvVarAvailability::Both]);
    }

    /** @return array<string, string> sorted by key */
    public static function build(Environment $environment): array
    {
        return self::merge([], $environment, [EnvVarAvailability::Build, EnvVarAvailability::Both]);
    }

    /**
     * @param  array<string, string>  $env
     * @param  list<EnvVarAvailability>  $availability
     * @return array<string, string>
     */
    private static function merge(array $env, Environment $environment, array $availability): array
    {
        $vars = $environment->envVars()->whereIn('available_at', $availability)->get();

        foreach ([EnvVarSource::Datastore, EnvVarSource::User] as $source) {
            foreach ($vars->where('source', $source) as $var) {
                $env[$var->key] = (string) $var->value;
            }
        }

        ksort($env, SORT_STRING);

        return $env;
    }

    /** @return array<string, string> */
    private static function systemDefaults(Environment $environment): array
    {
        $application = $environment->application;

        return $application->type->systemEnv($application->port, $environment->domains()->orderBy('id')->pluck('hostname')->all());
    }
}
