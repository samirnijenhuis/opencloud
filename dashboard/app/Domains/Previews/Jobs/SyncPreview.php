<?php

namespace App\Domains\Previews\Jobs;

use App\Domains\Applications\Models\Application;
use App\Domains\GitHub\PullRequestEvent;
use App\Domains\Previews\Actions\ClosePreview;
use App\Domains\Previews\Actions\OpenPreview;
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
                $open->execute($application, $this->pullRequest);

                continue;
            }

            if (! $close->execute($application, $this->pullRequest->number)) {
                $this->release(self::RETRY_SECONDS);

                return;
            }
        }
    }
}
