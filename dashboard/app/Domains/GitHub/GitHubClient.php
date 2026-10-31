<?php

namespace App\Domains\GitHub;

use App\Domains\GitHub\Enums\AppReach;
use App\Domains\GitHub\Models\GithubApp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Calls the GitHub API as the GitHub App of this install. Tests swap every call with Http::fake().
 */
class GitHubClient
{
    public const API = 'https://api.github.com';

    /**
     * Exchanges the code of the manifest flow for the new app and its credentials. GitHub accepts each code once, for one hour.
     *
     * @return array{id: int, slug: string, owner: string, pem: string, webhook_secret: string}
     *
     * @throws RequestException when GitHub refuses the code
     */
    public function convertManifest(string $code): array
    {
        $app = $this->request()->post('/app-manifests/'.rawurlencode($code).'/conversions')->throw()->json();

        return [
            'id' => (int) $app['id'],
            'slug' => (string) $app['slug'],
            'owner' => (string) ($app['owner']['login'] ?? ''),
            'pem' => (string) $app['pem'],
            'webhook_secret' => (string) $app['webhook_secret'],
        ];
    }

    /**
     * @return list<array{account: string, repository_selection: string}>
     *
     * @throws RequestException when GitHub refuses the app
     */
    public function installations(GithubApp $app): array
    {
        $installations = $this->asApp($app)->get('/app/installations')->throw()->json();

        return array_map(fn (array $installation) => [
            'account' => (string) $installation['account']['login'],
            'repository_selection' => (string) $installation['repository_selection'],
        ], $installations);
    }

    /**
     * An installation token gives the rights of the app on one installation for one hour.
     * The cache keeps it encrypted until one minute before it expires.
     *
     * @param  string  $repository  "owner/name"
     * @return string|null null when this install has no GitHub App, or the app is not installed on the repository
     *
     * @throws RequestException when GitHub refuses the app
     */
    public function installationToken(string $repository): ?string
    {
        $app = GithubApp::query()->first();

        if ($app === null) {
            return null;
        }

        $key = "github-token:{$app->app_id}:{$repository}";
        $cached = Cache::get($key);

        if (is_string($cached)) {
            return Crypt::decryptString($cached);
        }

        $installation = $this->asApp($app)->get("/repos/{$repository}/installation");

        if ($installation->notFound()) {
            return null;
        }

        $created = $this->asApp($app)->post('/app/installations/'.$installation->throw()->json('id').'/access_tokens')->throw();
        $token = (string) $created->json('token');
        Cache::put($key, Crypt::encryptString($token), Carbon::parse($created->json('expires_at'))->subMinute());

        return $token;
    }

    /**
     * The manifest creates the app as private, and GitHub installs a private app only on the account that owns it.
     * So a repository of another account needs no API call.
     *
     * @param  string  $repository  "owner/name"
     *
     * @throws RequestException when GitHub refuses the app
     */
    public function reach(string $repository): AppReach
    {
        $app = GithubApp::query()->first();

        if ($app === null) {
            return AppReach::NO_APP;
        }

        if (strcasecmp(Str::before($repository, '/'), $app->owner) !== 0) {
            return AppReach::OTHER_ACCOUNT;
        }

        $installation = $this->asApp($app)->get("/repos/{$repository}/installation");

        if ($installation->notFound()) {
            return AppReach::NOT_INSTALLED;
        }

        $installation->throw();

        return AppReach::INSTALLED;
    }

    /**
     * Updates the comment $commentId, or creates a comment when $commentId is null or the comment is gone.
     *
     * @return int the id of the comment
     *
     * @throws RuntimeException when the app is not installed on the repository
     * @throws RequestException when GitHub refuses the call
     */
    public function comment(string $repository, int $issue, string $body, ?int $commentId = null): int
    {
        $token = $this->installationToken($repository)
            ?? throw new RuntimeException("The GitHub App is not installed on {$repository}.");

        if ($commentId !== null) {
            $updated = $this->request()->withToken($token)->patch("/repos/{$repository}/issues/comments/{$commentId}", ['body' => $body]);

            if (! $updated->notFound()) {
                return (int) $updated->throw()->json('id');
            }
        }

        return (int) $this->request()->withToken($token)->post("/repos/{$repository}/issues/{$issue}/comments", ['body' => $body])->throw()->json('id');
    }

    private function asApp(GithubApp $app): PendingRequest
    {
        return $this->request()->withToken(AppJwt::sign($app->app_id, $app->private_key, now()->getTimestamp()));
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::API)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(10);
    }
}
