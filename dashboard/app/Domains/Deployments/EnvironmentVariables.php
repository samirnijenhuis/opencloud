<?php

namespace App\Domains\Deployments;

use App\Domains\Environments\Enums\EnvVarAvailability;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Enums\Tool;
use App\Domains\Environments\Models\Environment;
use App\Domains\Routing\PrimaryDomain;

/**
 * Merges the env of an environment. A user value beats a datastore value, and both beat the system defaults.
 */
final class EnvironmentVariables
{
    /** @return array<string, string> sorted by key */
    public static function runtime(Environment $environment): array
    {
        return self::merge(self::systemDefaults($environment), $environment, [EnvVarAvailability::RUNTIME, EnvVarAvailability::BOTH]);
    }

    /** @return array<string, string> sorted by key */
    public static function build(Environment $environment): array
    {
        return self::merge([], $environment, [EnvVarAvailability::BUILD, EnvVarAvailability::BOTH]);
    }

    /**
     * @param  array<string, string>  $env
     * @param  list<EnvVarAvailability>  $availability
     * @return array<string, string>
     */
    private static function merge(array $env, Environment $environment, array $availability): array
    {
        $vars = $environment->envVars()->whereIn('available_at', $availability)->get();

        foreach ([EnvVarSource::DATASTORE, EnvVarSource::USER] as $source) {
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
        // The manifests of the deploy read the same loaded relation, so APP_URL and the Host header of the probe agree.
        $domains = $environment->domains->sortBy('id');

        $env = $application->type->systemEnv($application->port, PrimaryDomain::of($domains)?->hostname, $domains->pluck('hostname')->values()->all());

        // The app reaches the Service of Mailpit in its own namespace by the name of the tool.
        return in_array(Tool::MAILPIT, $environment->runningTools(), true)
            ? [...$env, ...$application->type->mailEnv(Tool::MAILPIT->value, Tool::SMTP_PORT)]
            : $env;
    }
}
