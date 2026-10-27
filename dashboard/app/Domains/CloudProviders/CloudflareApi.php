<?php

namespace App\Domains\CloudProviders;

use App\Domains\CloudProviders\Exceptions\CloudflareException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The Cloudflare v4 calls that opencloud makes. Each call takes the token, so no token stays in memory.
 */
class CloudflareApi
{
    private const API = 'https://api.cloudflare.com/client/v4';

    private const MAX_PAGES = 20;

    /** @return string the status of the token, for example active */
    public function verify(string $token): string
    {
        return (string) $this->send($this->client($token)->get(self::API.'/user/tokens/verify'))['result']['status'];
    }

    /** @return list<array{id: string, name: string}> */
    public function zones(string $token): array
    {
        $zones = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $body = $this->send($this->client($token)->get(self::API.'/zones', ['per_page' => 50, 'page' => $page]));

            foreach ($body['result'] as $zone) {
                $zones[] = ['id' => $zone['id'], 'name' => $zone['name']];
            }

            if ($page >= ($body['result_info']['total_pages'] ?? 1)) {
                break;
            }
        }

        return $zones;
    }

    /**
     * @return list<array{id: string, content: string, proxied: bool}>
     */
    public function records(string $token, string $zoneId, ?string $hostname): array
    {
        $query = ['type' => 'A', 'per_page' => 5, ...($hostname === null ? [] : ['name' => $hostname])];
        $body = $this->send($this->client($token)->get(self::API."/zones/{$zoneId}/dns_records", $query));

        return array_map(
            fn (array $record) => ['id' => $record['id'], 'content' => $record['content'], 'proxied' => (bool) ($record['proxied'] ?? false)],
            $body['result'],
        );
    }

    /** @return string the id of the record */
    public function createRecord(string $token, string $zoneId, string $hostname, string $ip, bool $proxied): string
    {
        return $this->send($this->client($token)->post(self::API."/zones/{$zoneId}/dns_records", $this->record($hostname, $ip, $proxied)))['result']['id'];
    }

    public function updateRecord(string $token, string $zoneId, string $recordId, string $hostname, string $ip, bool $proxied): void
    {
        $this->send($this->client($token)->put(self::API."/zones/{$zoneId}/dns_records/{$recordId}", $this->record($hostname, $ip, $proxied)));
    }

    /** A record that is already gone counts as deleted. */
    public function deleteRecord(string $token, string $zoneId, string $recordId): void
    {
        $response = $this->client($token)->delete(self::API."/zones/{$zoneId}/dns_records/{$recordId}");

        if ($response->status() !== 404) {
            $this->send($response);
        }
    }

    /** @return array<string, mixed> */
    private function record(string $hostname, string $ip, bool $proxied): array
    {
        return ['type' => 'A', 'name' => $hostname, 'content' => $ip, 'ttl' => 1, 'proxied' => $proxied, 'comment' => 'Managed by opencloud'];
    }

    private function client(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout(15);
    }

    /** @return array<string, mixed> */
    private function send(Response $response): array
    {
        if ($response->successful() && $response->json('success') === true) {
            return $response->json();
        }

        throw new CloudflareException($response->json('errors.0.message') ?: "Cloudflare answered {$response->status()}.");
    }
}
