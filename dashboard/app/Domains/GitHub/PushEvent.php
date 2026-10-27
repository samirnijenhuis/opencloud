<?php

namespace App\Domains\GitHub;

/**
 * A push to one branch, parsed from the payload of a GitHub "push" webhook.
 */
final readonly class PushEvent
{
    public function __construct(
        public string $repository,
        public string $branch,
        public string $headSha,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return self|null null for a tag, a deleted branch, or a payload without a commit
     */
    public static function fromPayload(array $payload): ?self
    {
        $ref = (string) ($payload['ref'] ?? '');
        $sha = (string) ($payload['after'] ?? '');
        $repository = RepositoryName::parse((string) ($payload['repository']['full_name'] ?? ''));

        if (($payload['deleted'] ?? false) === true || ! str_starts_with($ref, 'refs/heads/') || ! Sha::valid($sha) || $repository === null) {
            return null;
        }

        return new self($repository, substr($ref, strlen('refs/heads/')), $sha);
    }
}
