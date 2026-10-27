<?php

namespace Tests\Domains\GitHub;

use App\Domains\GitHub\AppJwt;
use App\Domains\GitHub\GitHubClient;
use App\Domains\GitHub\RepositoryName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\WithGitHubApp;
use Tests\TestCase;

class GitHubClientTest extends TestCase
{
    use RefreshDatabase;
    use WithGitHubApp;

    public function test_the_app_jwt_is_rs256_lives_ten_minutes_and_verifies_with_the_public_key(): void
    {
        $jwt = AppJwt::sign(4242, self::privateKey(), 1_800_000_000);
        [$header, $claims, $signature] = explode('.', $jwt);
        $publicKey = openssl_pkey_get_details(openssl_pkey_get_private(self::privateKey()))['key'];

        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], self::decode($header));
        $this->assertSame(['iat' => 1_799_999_940, 'exp' => 1_800_000_540, 'iss' => 4242], self::decode($claims));
        $this->assertSame(1, openssl_verify("{$header}.{$claims}", self::bytes($signature), $publicKey, OPENSSL_ALGO_SHA256));

        $forged = rtrim(strtr(base64_encode('{"iat":1,"exp":9999999999,"iss":4242}'), '+/', '-_'), '=');
        $this->assertSame(0, openssl_verify("{$header}.{$forged}", self::bytes($signature), $publicKey, OPENSSL_ALGO_SHA256));
    }

    public function test_an_installation_token_is_cached_encrypted_until_one_minute_before_it_expires(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 12:00:00'));
        $this->makeGitHubApp();
        $this->fakeGitHub();
        $client = app(GitHubClient::class);

        $this->assertSame(self::TOKEN, $client->installationToken('acme/shop'));
        $this->travelTo(Carbon::parse('2026-10-23 12:58:59'));
        $this->assertSame(self::TOKEN, $client->installationToken('acme/shop'));

        $this->assertSame([
            'GET https://api.github.com/repos/acme/shop/installation',
            'POST https://api.github.com/app/installations/77/access_tokens',
        ], $this->sentRequests());
        $this->assertStringNotContainsString(self::TOKEN, (string) Cache::get('github-token:4242:acme/shop'));

        $sentJwt = substr(Http::recorded()[0][0]->header('Authorization')[0], strlen('Bearer '));
        $this->assertSame(['iat' => Carbon::parse('2026-10-23 11:59:00')->getTimestamp(), 'exp' => Carbon::parse('2026-10-23 12:09:00')->getTimestamp(), 'iss' => 4242], self::decode(explode('.', $sentJwt)[1]));

        $this->travelTo(Carbon::parse('2026-10-23 12:59:01'));
        $this->assertSame(self::TOKEN, $client->installationToken('acme/shop'));
        $this->assertCount(4, $this->sentRequests());
    }

    public function test_there_is_no_token_without_an_app_or_when_the_app_is_not_installed(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.github.com/repos/acme/public/installation' => Http::response(['message' => 'Not Found'], 404)]);
        $client = app(GitHubClient::class);

        $this->assertNull($client->installationToken('acme/public'));
        $this->assertSame([], $this->sentRequests());

        $this->makeGitHubApp();
        $this->assertNull($client->installationToken('acme/public'));
        $this->assertSame(['GET https://api.github.com/repos/acme/public/installation'], $this->sentRequests());
    }

    public function test_repository_names_come_from_owner_and_name_or_a_github_url(): void
    {
        $this->assertSame(
            ['acme/shop', 'acme/shop', 'acme/shop.web', 'laravel/laravel', null, null],
            array_map(RepositoryName::parse(...), [
                'Acme/Shop',
                'https://github.com/acme/shop.git',
                'https://github.com/acme/shop.web',
                'https://github.com/laravel/laravel/',
                'https://git.example.com/acme/shop.git',
                'acme',
            ]),
        );
    }

    /** @return list<string> */
    private function sentRequests(): array
    {
        return array_map(fn (array $pair) => $pair[0]->method().' '.$pair[0]->url(), Http::recorded()->all());
    }

    /** @return array<string, mixed> */
    private static function decode(string $part): array
    {
        return json_decode(self::bytes($part), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function bytes(string $part): string
    {
        return base64_decode(strtr($part, '-_', '+/'));
    }
}
