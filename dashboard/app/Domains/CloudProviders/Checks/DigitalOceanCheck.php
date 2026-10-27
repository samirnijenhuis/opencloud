<?php

namespace App\Domains\CloudProviders\Checks;

use App\Domains\CloudProviders\CheckResult;
use App\Domains\CloudProviders\ProviderCheck;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class DigitalOceanCheck implements ProviderCheck
{
    private const API = 'https://api.digitalocean.com/v2';

    /** Each read endpoint that opencloud needs, and the right whose absence makes it answer 403. */
    private const PROBES = [
        'kubernetes' => '/kubernetes/clusters?per_page=1',
        'registry' => '/registry',
        'vpc' => '/vpcs?per_page=1',
        'database' => '/databases?per_page=1',
        'spaces_key' => '/spaces/keys?per_page=1',
    ];

    public function check(string $token): CheckResult
    {
        $account = $this->get($token, '/account');

        if ($account->status() === 401) {
            return CheckResult::invalid($this->message($account, 'DigitalOcean does not accept this token.'));
        }

        $missing = [];
        $reasons = [];

        if ($account->status() === 403) {
            $missing[] = 'account';
            $reasons[] = 'account: '.$this->message($account, 'forbidden');
        }

        foreach (self::PROBES as $right => $path) {
            $answer = $this->get($token, $path);

            if ($answer->status() === 403) {
                $missing[] = $right;
                $reasons[] = $right.': '.$this->message($answer, 'forbidden');
            }
        }

        if ($missing !== []) {
            return CheckResult::missing('DigitalOcean answered 403 for '.implode('; ', $reasons).'.', $missing);
        }

        $email = $account->json('account.email');

        return CheckResult::ok($email ? "The token works for the account {$email}." : 'The token works.');
    }

    private function get(string $token, string $path): Response
    {
        return Http::withToken($token)->acceptJson()->timeout(15)->get(self::API.$path);
    }

    private function message(Response $response, string $fallback): string
    {
        return $response->json('message') ?: $fallback;
    }
}
