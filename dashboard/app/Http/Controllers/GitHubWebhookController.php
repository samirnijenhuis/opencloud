<?php

namespace App\Http\Controllers;

use App\GitHub\PullRequestEvent;
use App\GitHub\PushEvent;
use App\Jobs\DeployPush;
use App\Jobs\SyncPreview;
use App\Models\GithubApp;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * GitHub waits at most 10 seconds for an answer, so this only checks the signature and queues the work.
 */
class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $app = GithubApp::query()->first();
        $body = $request->getContent();
        $signature = (string) $request->header('X-Hub-Signature-256');

        if ($app === null || ! hash_equals('sha256='.hash_hmac('sha256', $body, $app->webhook_secret), $signature)) {
            return response('The signature is not valid.', 401);
        }

        // GitHub sends the same delivery id again when someone redelivers a webhook.
        $delivery = (string) $request->header('X-GitHub-Delivery');

        if ($delivery !== '' && ! Cache::add("github-delivery:{$delivery}", true, now()->addDay())) {
            return response('This delivery is already handled.', 200);
        }

        $payload = json_decode($body, true);

        $job = match (is_array($payload) ? $request->header('X-GitHub-Event') : null) {
            'push' => ($push = PushEvent::fromPayload($payload)) ? new DeployPush($push) : null,
            'pull_request' => ($pullRequest = PullRequestEvent::fromPayload($payload)) ? new SyncPreview($pullRequest) : null,
            default => null,
        };

        if ($job === null) {
            return response('Opencloud ignores this event.', 202);
        }

        dispatch($job);

        return response('Queued.', 202);
    }
}
