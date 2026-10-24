<?php

namespace App\Environments;

use App\Enums\EnvironmentKind;
use App\Jobs\CheckDomain;
use App\Models\AuditEvent;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The free address of a permanent environment. sslip.io resolves the IP in the name, so it needs no DNS step.
 */
final class DefaultDomain
{
    private const MAX_LABEL = 63;

    private const MAX_ENVIRONMENT = 20;

    /** The application id goes into the name only when the plain name is taken. */
    public static function hostname(string $application, string $environment, string $ip, ?int $applicationId = null): string
    {
        $environment = self::slug($environment, self::MAX_ENVIRONMENT) ?: 'env';
        $tail = ($applicationId === null ? '' : "-{$applicationId}")."-{$environment}";
        $application = self::slug($application, self::MAX_LABEL - strlen($tail)) ?: 'app';

        return "{$application}{$tail}.{$ip}.sslip.io";
    }

    /**
     * Adds the domain once. It does nothing when the environment already has one, when it is not permanent,
     * or when the cluster has no IPv4 ingress address.
     */
    public function add(Environment $environment, ?User $by): ?Domain
    {
        $application = $environment->application;
        $ip = $application->cluster->ingress_ip;

        if ($environment->kind !== EnvironmentKind::Permanent
            || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || $environment->domains()->where('generated', true)->exists()) {
            return null;
        }

        $hostname = collect([
            self::hostname($application->name, $environment->name, $ip),
            self::hostname($application->name, $environment->name, $ip, $application->id),
        ])->first(fn (string $hostname) => ! Domain::query()->where('hostname', $hostname)->exists());

        if ($hostname === null) {
            return null;
        }

        $domain = $environment->domains()->create(['hostname' => $hostname, 'generated' => true]);

        AuditEvent::record($by, 'domain.added', $domain, ['environment_id' => $environment->id, 'hostname' => $hostname, 'generated' => true]);
        CheckDomain::dispatch($domain)->afterCommit();

        return $domain;
    }

    private static function slug(string $name, int $length): string
    {
        return trim(substr(preg_replace('/[^a-z0-9-]/', '', Str::slug($name)), 0, $length), '-');
    }
}
