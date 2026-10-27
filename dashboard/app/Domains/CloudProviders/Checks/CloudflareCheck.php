<?php

namespace App\Domains\CloudProviders\Checks;

use App\Domains\CloudProviders\CheckResult;
use App\Domains\CloudProviders\CloudflareApi;
use App\Domains\CloudProviders\Exceptions\CloudflareException;
use App\Domains\CloudProviders\ProviderCheck;

final class CloudflareCheck implements ProviderCheck
{
    public function __construct(private CloudflareApi $api) {}

    public function check(string $token): CheckResult
    {
        try {
            $status = $this->api->verify($token);
        } catch (CloudflareException $e) {
            return CheckResult::invalid($e->getMessage());
        }

        if ($status !== 'active') {
            return CheckResult::invalid("The token status is {$status}.");
        }

        try {
            $zones = $this->api->zones($token);
        } catch (CloudflareException $e) {
            return CheckResult::missing("Cloudflare refused the zone list: {$e->getMessage()}", ['zone_read']);
        }

        if ($zones === []) {
            return CheckResult::missing('The token works, but it sees no zones. Include the zones you want to use.', ['zones']);
        }

        $details = ['zones' => $zones];

        try {
            $this->api->records($token, $zones[0]['id'], null);
        } catch (CloudflareException $e) {
            return CheckResult::missing("Cloudflare refused the DNS records of {$zones[0]['name']}: {$e->getMessage()}", ['dns_edit'], $details);
        }

        $names = implode(', ', array_column($zones, 'name'));

        return CheckResult::ok("The token works. It sees these zones: {$names}.", $details);
    }
}
