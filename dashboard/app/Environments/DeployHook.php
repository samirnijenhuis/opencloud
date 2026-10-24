<?php

namespace App\Environments;

use App\Models\Environment;
use Illuminate\Support\Str;

/**
 * A secret URL that starts a deployment of the branch head, for CI. The token is the only proof, so it is long and encrypted at rest.
 */
final class DeployHook
{
    public static function newToken(): string
    {
        return Str::random(48);
    }

    public static function url(Environment $environment): ?string
    {
        return $environment->deploy_hook_token === null ? null : route('hooks.deploy', ['token' => $environment->deploy_hook_token]);
    }

    /**
     * The tokens are encrypted, so the database cannot look one up. Few environments have a hook, so each enabled token is compared.
     */
    public static function find(string $token): ?Environment
    {
        return Environment::query()
            ->where('deploy_hook_enabled', true)
            ->whereNotNull('deploy_hook_token')
            ->get()
            ->first(fn (Environment $environment) => hash_equals($environment->deploy_hook_token, $token));
    }
}
