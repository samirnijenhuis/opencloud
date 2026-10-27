<?php

namespace App\Domains\Routing\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use App\Domains\Routing\CloudflareDns;
use App\Domains\Routing\Enums\DnsMode;
use App\Domains\Routing\Jobs\CheckDomain;
use App\Domains\Routing\Models\Domain;
use Illuminate\Validation\ValidationException;

class AddDomain
{
    public function __construct(private CloudflareDns $cloudflare) {}

    /**
     * With $manageDns, opencloud points the Cloudflare A record at the cluster itself, and Caddy uses the DNS challenge.
     *
     * @throws ValidationException when the DNS mode is not possible for this hostname
     */
    public function execute(Environment $environment, User $by, string $hostname, bool $manageDns = false, bool $proxied = false): Domain
    {
        $hostname = rtrim(strtolower(trim($hostname)), '.');
        $attributes = ['hostname' => $hostname];

        if ($manageDns) {
            $attributes = [...$attributes, ...$this->pointCloudflare($environment, $hostname, $proxied)];
        }

        $domain = $environment->domains()->create($attributes);

        AuditEvent::record($by, 'domain.added', $domain, [
            'environment_id' => $environment->id,
            'hostname' => $hostname,
            ...($manageDns ? ['dns_mode' => $domain->dns_mode->value, 'proxied' => $proxied] : []),
        ]);
        CheckDomain::dispatch($domain)->afterCommit();

        return $domain;
    }

    /** @return array<string, mixed> */
    private function pointCloudflare(Environment $environment, string $hostname, bool $proxied): array
    {
        $credential = CloudflareDns::credential();
        $zone = CloudflareDns::zoneFor($credential, $hostname);
        $ip = $environment->application->cluster->ingress_ip;

        if ($zone === null) {
            throw ValidationException::withMessages(['manage_dns' => 'The Cloudflare key does not cover the zone of this domain.']);
        }

        if ($ip === null) {
            throw ValidationException::withMessages(['manage_dns' => 'The cluster has no ingress address yet.']);
        }

        $recordId = $this->cloudflare->point($credential, $zone, $hostname, $ip, $proxied);

        return [
            'dns_mode' => DnsMode::Cloudflare,
            'cloudflare_zone_id' => $recordId === null ? null : $zone['id'],
            'cloudflare_record_id' => $recordId,
        ];
    }
}
