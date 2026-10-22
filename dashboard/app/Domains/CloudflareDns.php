<?php

namespace App\Domains;

use App\CloudProviders\CloudflareApi;
use App\CloudProviders\CloudflareException;
use App\Models\Domain;
use App\Models\ProviderCredential;
use Illuminate\Validation\ValidationException;

/**
 * Creates and deletes the A record of a domain in Cloudflare. It uses the stored Cloudflare key.
 */
class CloudflareDns
{
    public function __construct(private CloudflareApi $api) {}

    public static function credential(): ?ProviderCredential
    {
        return ProviderCredential::query()->where('provider', 'cloudflare')->first();
    }

    /**
     * The zone of the key with the longest name that ends the hostname.
     *
     * @return array{id: string, name: string}|null
     */
    public static function zoneFor(?ProviderCredential $credential, string $hostname): ?array
    {
        $zones = $credential?->details['zones'] ?? [];
        $matches = array_filter($zones, fn (array $zone) => $hostname === $zone['name'] || str_ends_with($hostname, '.'.$zone['name']));
        usort($matches, fn (array $a, array $b) => strlen($b['name']) <=> strlen($a['name']));

        return $matches[0] ?? null;
    }

    /**
     * Points the A record of the hostname at the ingress IP. It updates a record that exists and creates one that does not.
     *
     * @param  array{id: string, name: string}  $zone
     * @return string|null the id of the record when this call created it
     *
     * @throws ValidationException when Cloudflare refuses
     */
    public function point(ProviderCredential $credential, array $zone, string $hostname, string $ip, bool $proxied): ?string
    {
        try {
            $existing = $this->api->records($credential->token, $zone['id'], $hostname)[0] ?? null;

            if ($existing !== null) {
                $this->api->updateRecord($credential->token, $zone['id'], $existing['id'], $hostname, $ip, $proxied);

                return null;
            }

            return $this->api->createRecord($credential->token, $zone['id'], $hostname, $ip, $proxied);
        } catch (CloudflareException $e) {
            throw ValidationException::withMessages(['manage_dns' => "Cloudflare refused the DNS record: {$e->getMessage()}"]);
        }
    }

    /**
     * Deletes the record that opencloud created for the domain. Without a stored record id it does nothing.
     *
     * @throws ValidationException when Cloudflare refuses
     */
    public function remove(Domain $domain): void
    {
        $credential = self::credential();

        if ($domain->cloudflare_record_id === null || $domain->cloudflare_zone_id === null || $credential === null) {
            return;
        }

        try {
            $this->api->deleteRecord($credential->token, $domain->cloudflare_zone_id, $domain->cloudflare_record_id);
        } catch (CloudflareException $e) {
            throw ValidationException::withMessages(['domain' => "Cloudflare did not delete the DNS record of {$domain->hostname}: {$e->getMessage()}"]);
        }
    }
}
