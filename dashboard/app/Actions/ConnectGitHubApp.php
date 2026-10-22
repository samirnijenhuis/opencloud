<?php

namespace App\Actions;

use App\GitHub\GitHubClient;
use App\Models\AuditEvent;
use App\Models\GithubApp;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;

class ConnectGitHubApp
{
    public function __construct(private GitHubClient $client) {}

    /**
     * Exchanges the code of the manifest flow and stores the new app. One install has one app, so it replaces the old one.
     *
     * @throws RequestException when GitHub refuses the code
     */
    public function handle(User $by, string $code): GithubApp
    {
        $converted = $this->client->convertManifest($code);

        return DB::transaction(function () use ($by, $converted) {
            GithubApp::query()->delete();

            $app = GithubApp::create([
                'app_id' => $converted['id'],
                'slug' => $converted['slug'],
                'owner' => $converted['owner'],
                'private_key' => $converted['pem'],
                'webhook_secret' => $converted['webhook_secret'],
            ]);

            AuditEvent::record($by, 'github_app.created', $app, ['app_id' => $app->app_id, 'slug' => $app->slug, 'owner' => $app->owner]);

            return $app;
        });
    }
}
