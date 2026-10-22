<?php

namespace App\Jobs;

use App\GitHub\PullRequestEvent;
use App\Models\Application;
use App\Previews\ClosePreview;
use App\Previews\OpenPreview;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Opens, redeploys, or closes the preview of one pull request in each application of the repository.
 */
class SyncPreview implements ShouldQueue
{
    use Queueable;

    private const RETRY_SECONDS = 30;

    public function __construct(public PullRequestEvent $pullRequest) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("preview-{$this->pullRequest->repository}-{$this->pullRequest->number}"))->releaseAfter(10)->expireAfter(600)];
    }

    /** A close waits for a running deployment by releasing the job, so tries do not limit it. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(OpenPreview $open, ClosePreview $close): void
    {
        foreach (Application::forGitHubRepository($this->pullRequest->repository) as $application) {
            if (! $this->pullRequest->action->closes()) {
                $open->handle($application, $this->pullRequest);

                continue;
            }

            if (! $close->handle($application, $this->pullRequest->number)) {
                $this->release(self::RETRY_SECONDS);

                return;
            }
        }
    }
}
