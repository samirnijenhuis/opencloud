<?php

namespace Tests\Domains\GitHub;

use App\Domains\GitHub\AppJwt;
use App\Domains\GitHub\Enums\AppReach;
use App\Domains\GitHub\GitHubClient;
use App\Domains\GitHub\RepositoryName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
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
        $this->assertSame('{}', Http::recorded()[1][0]->body());
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

    public function test_the_private_app_reaches_only_the_installed_repositories_of_its_own_account(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.github.com/repos/acme/shop/installation' => Http::response(['id' => 77]),
            'api.github.com/repos/acme/blog/installation' => Http::response(['message' => 'Not Found'], 404),
            'api.github.com/repos/acme/down/installation' => Http::response(['message' => 'Server Error'], 500),
        ]);
        $client = app(GitHubClient::class);

        $this->assertSame(AppReach::NO_APP, $client->reach('acme/shop'));
        $this->assertSame([], $this->sentRequests());

        $this->makeGitHubApp()->update(['owner' => 'Acme']);
        $this->assertSame(
            [AppReach::INSTALLED, AppReach::NOT_INSTALLED, AppReach::OTHER_ACCOUNT],
            [$client->reach('acme/shop'), $client->reach('acme/blog'), $client->reach('samir/shop')],
        );
        $this->assertSame([
            'GET https://api.github.com/repos/acme/shop/installation',
            'GET https://api.github.com/repos/acme/blog/installation',
        ], $this->sentRequests());

        $this->expectException(RequestException::class);
        $client->reach('acme/down');
    }

    public function test_the_open_pull_requests_come_as_plain_values_with_their_labels_and_whether_they_come_from_a_fork(): void
    {
        $this->makeGitHubApp();
        $this->fakeGitHub(more: ['api.github.com/repos/acme/shop/pulls?*' => Http::response([
            self::apiPullRequest(12, ['preview', 'Bug']),
            self::apiPullRequest(13, [], 'mallory/shop'),
            self::apiPullRequest(14, [], null),
        ])]);

        $this->assertSame([
            ['number' => 12, 'title' => 'Change number 12', 'author' => 'samir', 'head_ref' => 'feature/12', 'head_sha' => self::HEAD_SHA, 'labels' => ['preview', 'Bug'], 'url' => 'https://github.com/acme/shop/pull/12', 'from_fork' => false, 'open' => true],
            ['number' => 13, 'title' => 'Change number 13', 'author' => 'samir', 'head_ref' => 'feature/13', 'head_sha' => self::HEAD_SHA, 'labels' => [], 'url' => 'https://github.com/acme/shop/pull/13', 'from_fork' => true, 'open' => true],
            ['number' => 14, 'title' => 'Change number 14', 'author' => 'samir', 'head_ref' => 'feature/14', 'head_sha' => self::HEAD_SHA, 'labels' => [], 'url' => 'https://github.com/acme/shop/pull/14', 'from_fork' => true, 'open' => true],
        ], app(GitHubClient::class)->openPullRequests('acme/shop'));

        $list = Http::recorded()[2][0];
        $this->assertSame('https://api.github.com/repos/acme/shop/pulls?state=open&per_page=100', $list->url());
        $this->assertSame('Bearer '.self::TOKEN, $list->header('Authorization')[0]);
    }

    public function test_one_pull_request_comes_with_its_state_and_an_unknown_number_gives_null(): void
    {
        $this->makeGitHubApp();
        $this->fakeGitHub(more: [
            'api.github.com/repos/acme/shop/pulls/12' => Http::response(self::apiPullRequest(12, state: 'closed')),
            'api.github.com/repos/acme/shop/pulls/99' => Http::response(['message' => 'Not Found'], 404),
        ]);
        $client = app(GitHubClient::class);

        $this->assertSame(
            ['number' => 12, 'title' => 'Change number 12', 'author' => 'samir', 'head_ref' => 'feature/12', 'head_sha' => self::HEAD_SHA, 'labels' => [], 'url' => 'https://github.com/acme/shop/pull/12', 'from_fork' => false, 'open' => false],
            $client->pullRequest('acme/shop', 12),
        );
        $this->assertNull($client->pullRequest('acme/shop', 99));
    }

    public function test_without_an_app_on_the_repository_there_are_no_pull_requests_to_read(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.github.com/repos/acme/blog/installation' => Http::response(['message' => 'Not Found'], 404)]);
        $client = app(GitHubClient::class);

        $this->assertNull($client->openPullRequests('acme/blog'));
        $this->assertSame([], $this->sentRequests());

        $this->makeGitHubApp();
        $this->assertSame([null, null], [$client->openPullRequests('acme/blog'), $client->pullRequest('acme/blog', 12)]);
        $this->assertSame([
            'GET https://api.github.com/repos/acme/blog/installation',
            'GET https://api.github.com/repos/acme/blog/installation',
        ], $this->sentRequests());
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
