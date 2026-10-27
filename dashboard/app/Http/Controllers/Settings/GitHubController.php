<?php

namespace App\Http\Controllers\Settings;

use App\Domains\GitHub\Actions\ConnectGitHubApp;
use App\Domains\GitHub\GitHubClient;
use App\Domains\GitHub\Manifest;
use App\Domains\GitHub\Models\GithubApp;
use App\Domains\Members\Enums\Ability;
use App\Http\Controllers\Controller;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class GitHubController extends Controller
{
    private const STATE = 'github_manifest_state';

    public function index(Request $request, GitHubClient $client): Response
    {
        $this->authorizeOwner($request);

        $state = Str::random(40);
        $request->session()->put(self::STATE, $state);
        $app = GithubApp::query()->first();

        return Inertia::render('settings/github', [
            'app' => $app ? $this->appProps($app, $client) : null,
            'manifest' => json_encode(Manifest::for(config('app.url')), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'state' => $state,
            'status' => $request->session()->get('status'),
        ]);
    }

    /** GitHub sends the browser back here with a code after it created the app. */
    public function callback(Request $request, ConnectGitHubApp $connect): RedirectResponse
    {
        $this->authorizeOwner($request);

        $expected = (string) $request->session()->pull(self::STATE, '');
        $code = $request->query('code');

        if ($expected === '' || ! is_string($code) || ! hash_equals($expected, (string) $request->query('state'))) {
            return to_route('github.index')->withErrors(['github' => 'This link from GitHub is not valid. Create the GitHub App again.']);
        }

        try {
            $connect->execute($request->user(), $code);
        } catch (RequestException $e) {
            return to_route('github.index')->withErrors(['github' => "GitHub did not accept the code. It answered with status {$e->response->status()}. Create the GitHub App again."]);
        }

        return to_route('github.index')->with('status', 'Opencloud is connected to the new GitHub App. Install it on your repositories next.');
    }

    /**
     * The page shows the installations, so the owner sees whether the app can reach the repositories.
     *
     * @return array<string, mixed>
     */
    private function appProps(GithubApp $app, GitHubClient $client): array
    {
        try {
            $installations = $client->installations($app);
            $error = null;
        } catch (Throwable $e) {
            report($e);
            $installations = null;
            $error = 'Opencloud could not read the installations of the app from GitHub.';
        }

        return [
            'app_id' => $app->app_id,
            'slug' => $app->slug,
            'owner' => $app->owner,
            'html_url' => "https://github.com/apps/{$app->slug}",
            'install_url' => $app->installUrl(),
            'installations' => $installations,
            'error' => $error,
        ];
    }

    private function authorizeOwner(Request $request): void
    {
        abort_unless($request->user()->role->allows(Ability::MANAGE_CREDENTIALS), 403);
    }
}
