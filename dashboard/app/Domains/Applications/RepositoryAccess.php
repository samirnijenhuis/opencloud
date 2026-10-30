<?php

namespace App\Domains\Applications;

use App\Domains\Applications\Enums\PreviewDelivery;
use App\Domains\Applications\Enums\PushDelivery;
use App\Domains\Applications\Enums\RepositoryRead;
use App\Domains\Applications\Models\Application;
use App\Domains\Builds\Exceptions\DeployKeyRefused;
use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\GitHub\Enums\AppReach;
use Carbon\CarbonImmutable;

/** What RepositoryProbe found: how opencloud reads the repository, and whether the GitHub App reaches it. */
final readonly class RepositoryAccess
{
    /**
     * @param  string  $repository  the clone URL
     * @param  ?string  $githubRepository  "owner/name", null for a repository on another host
     * @param  ?string  $branch  the branch that the check proved, null when it read only the default branch
     * @param  ?string  $defaultBranch  set when git could read the repository
     */
    public function __construct(
        public string $repository,
        public ?string $githubRepository,
        public ?string $branch,
        public RepositoryRead $read,
        public ?string $defaultBranch,
        public AppReach $app,
        public CarbonImmutable $checkedAt,
    ) {}

    /** Null for a preview and the preview template, because a pull request deploys them. */
    public function push(Environment $environment): ?PushDelivery
    {
        if ($environment->kind !== EnvironmentKind::PERMANENT) {
            return null;
        }

        if ($environment->auto_deploy && $this->app === AppReach::INSTALLED) {
            return PushDelivery::GITHUB_APP;
        }

        if ($environment->deploy_hook_enabled) {
            return PushDelivery::DEPLOY_HOOK;
        }

        return $environment->auto_deploy ? PushDelivery::NOT_CONNECTED : PushDelivery::OFF;
    }

    public function previews(Application $application): PreviewDelivery
    {
        if (! $application->previews_enabled) {
            return PreviewDelivery::OFF;
        }

        return $this->app === AppReach::INSTALLED ? PreviewDelivery::ON : PreviewDelivery::NOT_CONNECTED;
    }

    /** The one sentence for the read state. The create errors and the deploy errors use it too. */
    public function summary(): string
    {
        $name = $this->githubRepository ?? 'this repository';

        return match ($this->read) {
            RepositoryRead::GITHUB_APP => 'The GitHub App reads this repository.',
            RepositoryRead::DEPLOY_KEY => 'The deploy key reads this repository.',
            RepositoryRead::PUBLIC => $this->githubRepository === null
                ? 'Public repository on '.parse_url($this->repository, PHP_URL_HOST).'. Opencloud can read it.'
                : 'Public repository. Opencloud can read it.',
            RepositoryRead::BRANCH_MISSING => $this->defaultBranch === null
                ? "The branch {$this->branch} does not exist in {$name}."
                : "The branch {$this->branch} does not exist in {$name}. The default branch is {$this->defaultBranch}.",
            RepositoryRead::KEY_REFUSED => DeployKeyRefused::STEP,
            RepositoryRead::UNREADABLE => $this->githubRepository === null
                ? 'Opencloud cannot read this repository. Outside github.com, opencloud reads only a public repository.'
                : "Opencloud cannot read {$name}. It is private, or the name is wrong.",
        };
    }
}
