<?php

namespace App\CloudProviders\Checks;

use App\CloudProviders\CheckResult;
use App\CloudProviders\ProviderCheck;
use Illuminate\Support\Facades\Http;

final class HetznerCheck implements ProviderCheck
{
    public function check(string $token): CheckResult
    {
        $response = Http::withToken($token)->acceptJson()->timeout(15)->get('https://api.hetzner.cloud/v1/servers?per_page=1');

        if ($response->successful()) {
            return CheckResult::ok('The token can read the project. Hetzner does not show if it is Read & Write, so make sure you selected Read & Write.');
        }

        $message = $response->json('error.message') ?: 'Hetzner does not accept this token.';

        return $response->status() === 403
            ? CheckResult::missing("Hetzner answered 403: {$message}", ['read_write'])
            : CheckResult::invalid($message);
    }
}
