<?php

namespace App\Domains\Previews\Actions;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\GitHub\Enums\PullRequestAction;
use App\Domains\GitHub\GitHubClient;
use App\Domains\GitHub\PullRequestEvent;
use App\Domains\GitHub\RepositoryName;
use App\Domains\Members\Models\User;
use App\Domains\Previews\PreviewRules;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;

/**
 * Opens or redeploys the preview of one open pull request by hand, for example a pull request that was open before
 * previews were turned on. It reads the pull request from GitHub, so the preview runs its current head commit.
 */
class DeployPullRequest
{
    public function __construct(private GitHubClient $github, private OpenPreview $openPreview) {}

    /**
     * @throws ValidationException when the pull request cannot get a preview now
     * @throws RequestException when GitHub refuses the call
     */
    public function execute(Application $application, User $by, int $number): void
    {
        $repository = RepositoryName::parse($application->repository);
        $pullRequest = $repository === null ? null : $this->github->pullRequest($repository, $number);

        if ($pullRequest === null) {
            throw ValidationException::withMessages(['deploy' => "Opencloud cannot read pull request #{$number}. Check that the GitHub App is installed on the repository."]);
        }

        if (! $pullRequest['open']) {
            throw ValidationException::withMessages(['deploy' => "Pull request #{$number} is closed. Only an open pull request gets a preview."]);
        }

        $refusal = PreviewRules::refusal($application, $pullRequest['from_fork'], $pullRequest['labels']);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['deploy' => $refusal]);
        }

        // OpenPreview comments on the pull request at the limit. A user who clicks gets the message here instead.
        if ($application->environments()->where('kind', EnvironmentKind::PREVIEW)->where('pr_number', $number)->doesntExist() && PreviewRules::atLimit($application)) {
            throw ValidationException::withMessages(['deploy' => "{$application->name} already runs {$application->max_previews} previews, which is the limit. Close a pull request that has a preview, or raise the limit."]);
        }

        AuditEvent::record($by, 'preview.deploy_requested', $application, ['pr_number' => $number, 'commit_sha' => $pullRequest['head_sha']]);

        $this->openPreview->execute($application, $by, new PullRequestEvent(
            PullRequestAction::OPENED,
            $repository,
            $number,
            $pullRequest['head_ref'],
            $pullRequest['head_sha'],
            $pullRequest['from_fork'],
            $pullRequest['labels'],
            null,
        ));
    }
}
