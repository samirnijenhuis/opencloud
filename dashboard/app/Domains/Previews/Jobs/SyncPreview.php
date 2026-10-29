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
 * With a label filter, a pull request without one of the labels loses its preview.
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
        $action = $this->pullRequest->action;

        // Only a label of the filter decides a preview. Without a filter, a label change does nothing, as before the filter existed.
        $applications = Application::forGitHubRepository($this->pullRequest->repository)
            ->reject(fn (Application $application) => $action->changesLabels() && ! $this->hasFilterLabel($application, [$this->pullRequest->changedLabel]));

        [$opens, $closes] = $applications->partition(fn (Application $application) => ! $action->closes()
            && ($application->preview_labels === [] || $this->hasFilterLabel($application, $this->pullRequest->labels)));

        // A close that waits releases the job, so the closes go first. A retry then never deploys an opened preview again.
        foreach ($closes as $application) {
            if (! $close->execute($application, $this->pullRequest->number)) {
                $this->release(self::RETRY_SECONDS);

                return;
            }
        }

        foreach ($opens as $application) {
            $open->execute($application, $this->pullRequest);
        }
    }

    /**
     * GitHub compares label names without case, so this does too.
     *
     * @param  list<string>  $labels
     */
    private function hasFilterLabel(Application $application, array $labels): bool
    {
        return array_intersect(array_map(mb_strtolower(...), $application->preview_labels), array_map(mb_strtolower(...), $labels)) !== [];
    }
}
