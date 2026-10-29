<?php

namespace App\Domains\GitHub;

use App\Domains\GitHub\Enums\PullRequestAction;

/**
 * A change to one pull request, parsed from the payload of a GitHub "pull_request" webhook.
 */
final readonly class PullRequestEvent
{
    /**
     * @param  list<string>  $labels  the label names of the pull request after the action
     * @param  string|null  $changedLabel  the label that "labeled" or "unlabeled" adds or removes
     */
    public function __construct(
        public PullRequestAction $action,
        public string $repository,
        public int $number,
        public string $headRef,
        public string $headSha,
        public bool $fromFork,
        public array $labels,
        public ?string $changedLabel,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return self|null null for an action that does not change a preview
     */
    public static function fromPayload(array $payload): ?self
    {
        $action = PullRequestAction::tryFrom((string) ($payload['action'] ?? ''));
        $pullRequest = $payload['pull_request'] ?? null;
        $repository = RepositoryName::parse((string) ($payload['repository']['full_name'] ?? ''));

        if ($action === null || ! is_array($pullRequest) || $repository === null || ! Sha::valid((string) ($pullRequest['head']['sha'] ?? ''))) {
            return null;
        }

        // GitHub also sends the label changes of a closed pull request, and a closed pull request has no preview.
        if ($action->changesLabels() && ($pullRequest['state'] ?? '') !== 'open') {
            return null;
        }

        // GitHub sends no head repository when the fork is gone, so that counts as a fork too.
        $headRepository = RepositoryName::parse((string) ($pullRequest['head']['repo']['full_name'] ?? ''));

        return new self(
            $action,
            $repository,
            (int) $pullRequest['number'],
            (string) $pullRequest['head']['ref'],
            (string) $pullRequest['head']['sha'],
            $headRepository !== $repository,
            array_column($pullRequest['labels'] ?? [], 'name'),
            $payload['label']['name'] ?? null,
        );
    }
}
