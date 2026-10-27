<?php

namespace Tests\Concerns;

use App\Domains\GitHub\Models\GithubApp;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

trait WithGitHubApp
{
    private const WEBHOOK_SECRET = 'whsec-test-1234';

    private const TOKEN = 'ghs_installationtoken0123456789';

    private static ?string $privateKey = null;

    /** One RSA key per test run, because creating a key takes a moment. */
    protected static function privateKey(): string
    {
        if (self::$privateKey === null) {
            openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
            self::$privateKey = $pem;
        }

        return self::$privateKey;
    }

    protected function makeGitHubApp(): GithubApp
    {
        return GithubApp::factory()->create([
            'app_id' => 4242,
            'slug' => 'opencloud-dash',
            'owner' => 'acme',
            'private_key' => self::privateKey(),
            'webhook_secret' => self::WEBHOOK_SECRET,
        ]);
    }

    /**
     * Answers the installation lookup and the token request for $repository, and records comments.
     *
     * @param  array<string, mixed>  $more  extra fake responses by URL pattern
     */
    protected function fakeGitHub(string $repository = 'acme/shop', array $more = []): void
    {
        Http::preventStrayRequests();
        Http::fake([
            ...$more,
            "api.github.com/repos/{$repository}/installation" => Http::response(['id' => 77]),
            'api.github.com/app/installations/77/access_tokens' => Http::response(['token' => self::TOKEN, 'expires_at' => now()->addHour()->toIso8601ZuluString()], 201),
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    protected function postWebhook(string $event, array $payload, ?string $secret = self::WEBHOOK_SECRET, string $delivery = 'delivery-1'): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = ['X-GitHub-Event' => $event, 'X-GitHub-Delivery' => $delivery, 'Content-Type' => 'application/json'];

        if ($secret !== null) {
            $headers['X-Hub-Signature-256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', '/webhooks/github', server: $this->transformHeadersToServerVars($headers), content: $body);
    }
}
