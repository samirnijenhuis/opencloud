<?php

namespace App\Domains\Builds;

use App\Domains\Applications\Enums\ApplicationType;
use App\Domains\Applications\Models\Application;
use App\Domains\Builds\Exceptions\RepositoryDoesNotFit;
use App\Domains\Deployments\DeploymentLog;
use App\Domains\Deployments\Enums\DeploymentStep;
use App\Domains\Deployments\EnvironmentVariables;
use App\Domains\Deployments\Models\Deployment;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Clones one commit and builds its image with BuildKit. Process::fake() swaps every command in tests.
 * The clone, the Dockerfile, and the build values live in three folders, so the builder never writes into the clone,
 * where a link could send the write to a file of the builder container, for example /usr/local/bin/kubectl.
 */
final class Builder
{
    public const GENERATED_DOCKERFILE = '.opencloud.Dockerfile';

    /** The Dockerfile of the repository, with the build env arg in each stage. */
    public const DECLARED_DOCKERFILE = '.opencloud.repository.Dockerfile';

    /**
     * BuildKit leaves the values of secrets out of its cache key. So two builds of one commit with other build values
     * shared each layer that read a secret, also between apps and between a preview and production.
     * This arg puts a key of the build values into the cache key of each RUN after it.
     */
    public const BUILD_ENV_ARG = 'OPENCLOUD_BUILD_ENV';

    /** The preset templates put this key of the application in the id of each cache mount, so one application never reads the package cache of another. */
    public const CACHE_ID_ARG = 'OPENCLOUD_CACHE_ID';

    /** Files that a Laravel image never needs. BuildKit reads "<dockerfile>.dockerignore" next to the Dockerfile. */
    private const LARAVEL_IGNORE = ".git\n.env\nnode_modules\nvendor\n";

    /** Files that a Node image or a static site never needs. A static site without a build serves the repository root. */
    private const NODE_IGNORE = ".git\nnode_modules\n";

    private const PYTHON_IGNORE = ".git\n.venv\n";

    private const RUBY_IGNORE = ".git\nvendor/bundle\n";

    /**
     * @return string the image reference
     *
     * @throws RuntimeException when the clone or the build fails, or when the repository does not fit the preset
     */
    public function build(Deployment $deployment, DeploymentLog $log): string
    {
        $environment = $deployment->environment;
        $application = $environment->application;
        $root = sys_get_temp_dir().'/opencloud-build-'.$deployment->id.'-'.Str::lower(Str::random(10));
        $dir = "{$root}/repository";
        File::makeDirectory($dir, 0700, true);
        mkdir("{$root}/dockerfile");
        mkdir("{$root}/secrets");

        try {
            $log->step(DeploymentStep::PREPARING_BUILD);
            $log->write("Cloning {$application->repository} at {$deployment->commit_sha}.");
            Git::checkout($application, $deployment->commit_sha, $dir);

            $buildEnv = EnvironmentVariables::build($environment);
            $dockerfile = $this->prepare($application->type, $dir, "{$root}/dockerfile", array_keys($buildEnv), $environment->build_command);
            $image = self::imageReference($deployment);
            $log->step(DeploymentStep::RUNNING_BUILD);
            $log->write("Building {$image} from {$dockerfile}.");

            foreach ($buildEnv as $key => $value) {
                file_put_contents("{$root}/secrets/{$key}", $value);
            }

            // The build values never go into the env of buildctl. buildctl starts kubectl, so PATH=. or LD_PRELOAD would run a file of the repository.
            $exitCode = Process::path($dir)
                ->timeout(3600)
                ->start(self::buildctl(BuildKit::address(), $root, $dockerfile, $image, array_keys($buildEnv), self::buildEnvKey($buildEnv), self::cacheId($application)))
                ->wait(fn (string $type, string $output) => $log->chunk($output))
                ->exitCode();
            $log->flush();

            if ($exitCode !== 0) {
                throw new RuntimeException("buildctl exited with code {$exitCode}.");
            }

            return $image;
        } finally {
            File::deleteDirectory($root);
        }
    }

    /** The same name and tag in each registry. Without a registry, it is the name that BuildKit pushes to the local registry. */
    public static function imageReference(Deployment $deployment, ?string $registry = null): string
    {
        $environment = $deployment->environment;
        $tag = substr($deployment->commit_sha, 0, 7).'-'.$deployment->id;

        return ($registry ?? config('opencloud.registry_host'))."/{$environment->application_id}/{$environment->id}:{$tag}";
    }

    /**
     * @param  string  $root  the folder with the clone in repository/, the Dockerfile in dockerfile/, and a file for each build value in secrets/
     * @param  list<string>  $secrets
     * @return list<string>
     */
    public static function buildctl(string $address, string $root, string $dockerfile, string $image, array $secrets, string $buildEnvKey, string $cacheId): array
    {
        return [
            'buildctl', '--addr', $address,
            'build', '--progress=plain', '--frontend', 'dockerfile.v0',
            '--local', "context={$root}/repository", '--local', "dockerfile={$root}/dockerfile", '--opt', "filename={$dockerfile}",
            '--opt', 'build-arg:'.self::BUILD_ENV_ARG."={$buildEnvKey}",
            '--opt', 'build-arg:'.self::CACHE_ID_ARG."={$cacheId}",
            ...array_merge(...array_map(fn (string $key) => ['--secret', "id={$key},src={$root}/secrets/{$key}"], $secrets)),
            '--output', "type=image,name={$image},push=true,registry.insecure=true",
        ];
    }

    /**
     * The preset of the application picks the Dockerfile. The repository only fills it in.
     *
     * @param  string  $dockerfileDir  an empty folder outside $dir
     * @param  list<string>  $secrets
     * @return string the name of the Dockerfile in $dockerfileDir
     */
    private function prepare(ApplicationType $type, string $dir, string $dockerfileDir, array $secrets, ?string $buildCommand): string
    {
        $files = RepositoryFiles::in($dir);

        return match ($type) {
            ApplicationType::LARAVEL => self::generate($dockerfileDir, self::LARAVEL_IGNORE, LaravelDockerfile::fromConfig()->render(
                self::laravelComposer($files), self::readJson($files, 'package.json'), $secrets, $buildCommand,
            )),
            ApplicationType::NODE => self::generate($dockerfileDir, self::NODE_IGNORE, NodeDockerfile::render(
                self::nodeProject($files) ?? throw new RepositoryDoesNotFit('The application uses the Node.js preset, but the repository has no package.json at its root.'),
                $secrets,
                $buildCommand,
            )),
            ApplicationType::PYTHON => self::generate($dockerfileDir, self::PYTHON_IGNORE, PythonDockerfile::render(
                PythonProject::in($files) ?? throw new RepositoryDoesNotFit('The application uses the Python preset, but the repository has no uv.lock, poetry.lock, Pipfile.lock, requirements.txt, or pyproject.toml at its root.'),
                $secrets,
                $buildCommand,
            )),
            ApplicationType::RUBY => self::generate($dockerfileDir, self::RUBY_IGNORE, RubyDockerfile::render(
                RubyProject::in($files) ?? throw new RepositoryDoesNotFit('The application uses the Ruby preset, but the repository has no Gemfile at its root.'),
                $secrets,
                $buildCommand,
            )),
            ApplicationType::STATIC => self::generate($dockerfileDir, self::NODE_IGNORE, StaticDockerfile::render(self::nodeProject($files), $secrets)),
            ApplicationType::DOCKERFILE => self::repositoryDockerfile($files, $dockerfileDir, $secrets),
        };
    }

    private static function nodeProject(RepositoryFiles $files): ?NodeProject
    {
        $package = self::readJson($files, 'package.json');
        $lockFiles = array_filter(NodeProject::LOCK_FILES, $files->has(...));

        return $package === null ? null : new NodeProject($package, $lockFiles === [] ? null : reset($lockFiles));
    }

    private static function generate(string $dockerfileDir, string $ignore, string $dockerfile): string
    {
        file_put_contents("{$dockerfileDir}/".self::GENERATED_DOCKERFILE, $dockerfile);
        file_put_contents("{$dockerfileDir}/".self::GENERATED_DOCKERFILE.'.dockerignore', $ignore);

        return self::GENERATED_DOCKERFILE;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RepositoryDoesNotFit when composer.json does not require laravel/framework
     */
    private static function laravelComposer(RepositoryFiles $files): array
    {
        $composer = self::readJson($files, 'composer.json');

        if (! isset($composer['require']['laravel/framework'])) {
            throw new RepositoryDoesNotFit('The application uses the Laravel preset, but the composer.json of the repository does not require laravel/framework.');
        }

        return $composer;
    }

    /**
     * @param  list<string>  $secrets
     *
     * @throws RepositoryDoesNotFit when the repository has no Dockerfile
     */
    private static function repositoryDockerfile(RepositoryFiles $files, string $dockerfileDir, array $secrets): string
    {
        $dockerfile = $files->read('Dockerfile') ?? throw new RepositoryDoesNotFit('The application uses the Dockerfile preset, but the repository has no Dockerfile at its root.');
        $name = $secrets === [] ? 'Dockerfile' : self::DECLARED_DOCKERFILE;
        file_put_contents("{$dockerfileDir}/{$name}", $secrets === [] ? $dockerfile : self::declareInEachStage($dockerfile, self::BUILD_ENV_ARG));

        // BuildKit reads "<dockerfile>.dockerignore" before ".dockerignore", so a Dockerfile-specific ignore file must move with it.
        if (($ignore = $files->read('Dockerfile.dockerignore')) !== null) {
            file_put_contents("{$dockerfileDir}/{$name}.dockerignore", $ignore);
        }

        return $name;
    }

    /**
     * An HMAC with the app key, so the image history never shows a plain hash of a secret that someone could guess.
     *
     * @param  array<string, string>  $buildEnv
     */
    public static function buildEnvKey(array $buildEnv): string
    {
        ksort($buildEnv);

        return hash_hmac('sha256', json_encode($buildEnv), (string) config('app.key'));
    }

    /** An HMAC with the app key, so a Dockerfile app cannot name the cache of another app in its own cache mount. */
    public static function cacheId(Application $application): string
    {
        return hash_hmac('sha256', "cache-{$application->id}", (string) config('app.key'));
    }

    /** Adds "ARG <name>" after each FROM instruction, because an ARG is in scope only in its own stage. */
    public static function declareInEachStage(string $dockerfile, string $arg): string
    {
        $lines = [];
        $continued = false;
        $inFrom = false;

        foreach (explode("\n", $dockerfile) as $line) {
            $lines[] = $line;
            $inFrom = $inFrom || (! $continued && preg_match('/^\s*FROM\s/i', $line) === 1);
            $continued = str_ends_with(rtrim($line), '\\');

            if ($inFrom && ! $continued) {
                $lines[] = "ARG {$arg}";
                $inFrom = false;
            }
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed>|null */
    private static function readJson(RepositoryFiles $files, string $path): ?array
    {
        $text = $files->read($path);

        if ($text === null) {
            return null;
        }

        $data = json_decode($text, true);

        if (! is_array($data)) {
            throw new RuntimeException("{$path} is not valid JSON.");
        }

        return $data;
    }
}
