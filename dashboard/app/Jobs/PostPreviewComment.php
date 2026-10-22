<?php

namespace App\Jobs;

use App\GitHub\GitHubClient;
use App\GitHub\RepositoryName;
use App\Models\Deployment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts the preview URL on the pull request when a preview deployment goes live. Later deployments update the same comment.
 */
class PostPreviewComment implements ShouldQueue
{
    use Queueable;

    /** The preview is gone when its pull request closed first. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Deployment $deployment) {}

    public function handle(GitHubClient $github): void
    {
        $preview = $this->deployment->environment;
        $repository = RepositoryName::parse($preview->application->repository);
        $hostname = $preview->domains()->orderBy('id')->value('hostname');

        if ($repository === null || $hostname === null) {
            return;
        }

        $sha = substr($this->deployment->commit_sha, 0, 7);
        $body = "Opencloud deployed commit {$sha} of this pull request.\n\nPreview: https://{$hostname}";

        try {
            $commentId = $github->comment($repository, $preview->pr_number, $body, $preview->pr_comment_id);
        } catch (Throwable $e) {
            // The preview runs without the comment, so a failure only goes to the log.
            Log::warning("Opencloud could not post the preview URL on {$repository}#{$preview->pr_number}.", ['error' => $e->getMessage()]);

            return;
        }

        $preview->update(['pr_comment_id' => $commentId]);
    }
}
