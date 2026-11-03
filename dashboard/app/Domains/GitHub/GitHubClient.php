<?php

namespace App\Domains\GitHub;

use App\Domains\GitHub\Enums\AppReach;
use App\Domains\GitHub\Models\GithubApp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
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
        // Without data, the HTTP client sends the JSON body []. GitHub refuses it: "[] is not a null or object".
        $app = $this->request()->post('/app-manifests/'.rawurlencode($code).'/conversions', (object) [])->throw()->json();

        return [
            'id' => (int) $app['id'],
            'slug' => (string) $app['slug'],
            'owner' => (string) ($app['owner']['login'] ?? ''),
            'pem' => (string) $app['pem'],
            'webhook_secret' => (string) $app['webhook_secret'],
        ];
    }

    /**
     * The reason in an error answer of GitHub: its message and the message of each validation error.
     * A validation error is an object with a message or only a code, or a plain string. An HTML error page gives "".
     */
    public static function errorText(Response $response): string
    {
        $errors = array_map(
            fn (mixed $error) => is_array($error) ? (string) ($error['message'] ?? $error['code'] ?? '') : (string) $error,
            (array) $response->json('errors', []),
        );

        return collect([$response->json('message'), ...$errors])
            ->filter(fn (mixed $text) => is_string($text) && $text !== '')
            ->map(fn (string $text) => rtrim($text, '.').'.')
            ->implode(' ');
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

        $created = $this->asApp($app)->post('/app/installations/'.$installation->throw()->json('id').'/access_tokens', (object) [])->throw();
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

    /**
     * The open pull requests of a repository, newest first. One page of 100 is the limit, so a repository with more
     * open pull requests shows only the newest 100.
     *
     * @param  string  $repository  "owner/name" from RepositoryName::parse()
     * @return list<array{number: int, title: string, author: string, head_ref: string, head_sha: string, labels: list<string>, url: string, from_fork: bool, open: bool}>|null null when this install has no GitHub App, or the app is not installed on the repository
     *
     * @throws RequestException when GitHub refuses the call
     */
    public function openPullRequests(string $repository): ?array
    {
        $token = $this->installationToken($repository);

        if ($token === null) {
            return null;
        }

        $pullRequests = $this->request()->withToken($token)->get("/repos/{$repository}/pulls", ['state' => 'open', 'per_page' => 100])->throw()->json();

        return array_map(fn (array $pullRequest) => self::pullRequestValues($pullRequest, $repository), $pullRequests);
    }

    /**
     * @param  string  $repository  "owner/name" from RepositoryName::parse()
     * @return array{number: int, title: string, author: string, head_ref: string, head_sha: string, labels: list<string>, url: string, from_fork: bool, open: bool}|null null when this install has no GitHub App, the app is not installed on the repository, or the pull request does not exist
     *
     * @throws RequestException when GitHub refuses the call
     */
    public function pullRequest(string $repository, int $number): ?array
    {
        $token = $this->installationToken($repository);

        if ($token === null) {
            return null;
        }

        // The number comes from the browser, so it can be an issue or a number that does not exist.
        $response = $this->request()->withToken($token)->get("/repos/{$repository}/pulls/{$number}");

        if ($response->notFound()) {
            return null;
        }

        return self::pullRequestValues($response->throw()->json(), $repository);
    }

    /**
     * @param  array<string, mixed>  $pullRequest  a pull request object of the GitHub API
     * @return array{number: int, title: string, author: string, head_ref: string, head_sha: string, labels: list<string>, url: string, from_fork: bool, open: bool}
     */
    private static function pullRequestValues(array $pullRequest, string $repository): array
    {
        return [
            'number' => (int) $pullRequest['number'],
            'title' => (string) $pullRequest['title'],
            'author' => (string) ($pullRequest['user']['login'] ?? ''),
            'head_ref' => (string) $pullRequest['head']['ref'],
            'head_sha' => (string) $pullRequest['head']['sha'],
            'labels' => array_column($pullRequest['labels'] ?? [], 'name'),
            'url' => (string) $pullRequest['html_url'],
            'from_fork' => PullRequestEvent::isFork($pullRequest, $repository),
            'open' => $pullRequest['state'] === 'open',
        ];
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
