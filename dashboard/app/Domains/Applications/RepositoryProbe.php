<?php

namespace App\Domains\Applications;

use App\Domains\Applications\Enums\RepositoryRead;
use App\Domains\Applications\Models\Application;
use App\Domains\Builds\Enums\ReadPath;
use App\Domains\Builds\Exceptions\DeployKeyRefused;
use App\Domains\Builds\Git;
use App\Domains\GitHub\Enums\AppReach;
use App\Domains\GitHub\GitHubClient;
use App\Domains\GitHub\RepositoryName;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\HttpClientException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The one reader of repository access, for the pages to poll. The status is derived on each read and lives only in the cache.
 */
final class RepositoryProbe
{
    private const CACHE_SECONDS = 10;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly GitHubClient $github,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * Reads the repository the way a build does: one git ls-remote through the remote choice of Git::withRemote().
     * With $branch, it proves the branch. Without it, it reads the default branch. Then it asks GitHubClient::reach().
     * It works for an unsaved application, so the create page can ask before the record exists.
     * The cache key holds the repository, a hash of the deploy key, and the branch, so a new key or a new branch gets
     * a fresh check without code that clears the cache.
     */
    public function access(Application $application, ?string $branch): RepositoryAccess
    {
        $key = "repository-access:{$application->repository}:".hash('sha256', (string) $application->deploy_private_key).":{$branch}";

        return $this->cache->remember($key, self::CACHE_SECONDS, function () use ($application, $branch) {
            $repository = RepositoryName::parse($application->repository);
            [$read, $defaultBranch] = $this->read($application, $branch);

            return new RepositoryAccess($application->cloneUrl(), $repository, $branch, $read, $defaultBranch, $this->reach($repository), now()->toImmutable());
        });
    }

    /** @return array{RepositoryRead, ?string} the read state and the default branch */
    private function read(Application $application, ?string $branch): array
    {
        try {
            $remote = Git::read($application, $branch);
        } catch (DeployKeyRefused) {
            return [RepositoryRead::KEY_REFUSED, null];
        } catch (RuntimeException) {
            return [RepositoryRead::UNREADABLE, null];
        }

        if (! $remote['branch_exists']) {
            return [RepositoryRead::BRANCH_MISSING, $remote['default_branch']];
        }

        $read = match ($remote['path']) {
            ReadPath::DEPLOY_KEY => RepositoryRead::DEPLOY_KEY,
            ReadPath::GITHUB_APP => RepositoryRead::GITHUB_APP,
            ReadPath::PLAIN_URL => RepositoryRead::PUBLIC,
        };

        return [$read, $remote['default_branch']];
    }

    /** A failed GitHub call must not hide what git found, so it gives UNKNOWN. */
    private function reach(?string $repository): AppReach
    {
        if ($repository === null) {
            return AppReach::NOT_GITHUB;
        }

        try {
            return $this->github->reach($repository);
        } catch (HttpClientException $e) {
            $this->log->warning("Opencloud could not ask GitHub whether the app reaches {$repository}.", ['error' => $e->getMessage()]);

            return AppReach::UNKNOWN;
        }
    }
}
