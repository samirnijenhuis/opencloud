<?php

namespace App\Domains\Builds;

use App\Domains\Applications\Enums\ApplicationType;
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
        $dir = sys_get_temp_dir().'/opencloud-build-'.$deployment->id.'-'.Str::lower(Str::random(10));
        File::makeDirectory($dir, 0700);

        try {
            $log->step(DeploymentStep::PREPARING_BUILD);
            $log->write("Cloning {$application->repository} at {$deployment->commit_sha}.");
            Git::checkout($application, $deployment->commit_sha, $dir);

            $buildEnv = EnvironmentVariables::build($environment);
            $dockerfile = $this->prepare($application->type, $dir, array_keys($buildEnv), $environment->build_command);
            $image = self::imageReference($deployment);
            $log->step(DeploymentStep::RUNNING_BUILD);
            $log->write("Building {$image} from {$dockerfile}.");

            $exitCode = Process::path($dir)
                ->env($buildEnv)
                ->timeout(3600)
                ->start(self::buildctl(BuildKit::address(), $dir, $dockerfile, $image, array_keys($buildEnv), self::buildEnvKey($buildEnv)))
                ->wait(fn (string $type, string $output) => $log->chunk($output))
                ->exitCode();
            $log->flush();

            if ($exitCode !== 0) {
                throw new RuntimeException("buildctl exited with code {$exitCode}.");
            }

            return $image;
        } finally {
            File::deleteDirectory($dir);
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
     * @param  list<string>  $secrets
     * @return list<string>
     */
    public static function buildctl(string $address, string $dir, string $dockerfile, string $image, array $secrets, string $buildEnvKey): array
    {
        return [
            'buildctl', '--addr', $address,
            'build', '--progress=plain', '--frontend', 'dockerfile.v0',
            '--local', "context={$dir}", '--local', "dockerfile={$dir}", '--opt', "filename={$dockerfile}",
            '--opt', 'build-arg:'.self::BUILD_ENV_ARG."={$buildEnvKey}",
            ...array_merge(...array_map(fn (string $key) => ['--secret', "id={$key},env={$key}"], $secrets)),
            '--output', "type=image,name={$image},push=true,registry.insecure=true",
        ];
    }

    /**
     * The preset of the application picks the Dockerfile. The repository only fills it in.
     *
     * @param  list<string>  $secrets
     * @return string the name of the Dockerfile in $dir
     */
    private function prepare(ApplicationType $type, string $dir, array $secrets, ?string $buildCommand): string
    {
        return match ($type) {
            ApplicationType::LARAVEL => self::generate($dir, self::LARAVEL_IGNORE, LaravelDockerfile::fromConfig()->render(
                self::laravelComposer($dir), self::readJson("{$dir}/package.json"), $secrets, $buildCommand,
            )),
            ApplicationType::NODE => self::generate($dir, self::NODE_IGNORE, NodeDockerfile::render(
                self::nodeProject($dir) ?? throw new RepositoryDoesNotFit('The application uses the Node.js preset, but the repository has no package.json at its root.'),
                $secrets,
                $buildCommand,
            )),
            ApplicationType::PYTHON => self::generate($dir, self::PYTHON_IGNORE, PythonDockerfile::render(
                PythonProject::in(RepositoryFiles::in($dir)) ?? throw new RepositoryDoesNotFit('The application uses the Python preset, but the repository has no uv.lock, poetry.lock, Pipfile.lock, requirements.txt, or pyproject.toml at its root.'),
                $secrets,
                $buildCommand,
            )),
            ApplicationType::RUBY => self::generate($dir, self::RUBY_IGNORE, RubyDockerfile::render(
                RubyProject::in(RepositoryFiles::in($dir)) ?? throw new RepositoryDoesNotFit('The application uses the Ruby preset, but the repository has no Gemfile at its root.'),
                $secrets,
                $buildCommand,
            )),
            ApplicationType::STATIC => self::generate($dir, self::NODE_IGNORE, StaticDockerfile::render(self::nodeProject($dir), $secrets)),
            ApplicationType::DOCKERFILE => self::repositoryDockerfile($dir, $secrets),
        };
    }

    private static function nodeProject(string $dir): ?NodeProject
    {
        $package = self::readJson("{$dir}/package.json");
        $lockFiles = array_filter(NodeProject::LOCK_FILES, fn (string $file) => is_file("{$dir}/{$file}"));

        return $package === null ? null : new NodeProject($package, $lockFiles === [] ? null : reset($lockFiles));
    }

    private static function generate(string $dir, string $ignore, string $dockerfile): string
    {
        file_put_contents("{$dir}/".self::GENERATED_DOCKERFILE, $dockerfile);
        file_put_contents("{$dir}/".self::GENERATED_DOCKERFILE.'.dockerignore', $ignore);

        return self::GENERATED_DOCKERFILE;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RepositoryDoesNotFit when composer.json does not require laravel/framework
     */
    private static function laravelComposer(string $dir): array
    {
        $composer = self::readJson("{$dir}/composer.json");

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
    private static function repositoryDockerfile(string $dir, array $secrets): string
    {
        if (! is_file("{$dir}/Dockerfile")) {
            throw new RepositoryDoesNotFit('The application uses the Dockerfile preset, but the repository has no Dockerfile at its root.');
        }

        if ($secrets === []) {
            return 'Dockerfile';
        }

        file_put_contents("{$dir}/".self::DECLARED_DOCKERFILE, self::declareInEachStage(file_get_contents("{$dir}/Dockerfile"), self::BUILD_ENV_ARG));

        // BuildKit reads "<dockerfile>.dockerignore" before ".dockerignore", so a Dockerfile-specific ignore file must move with it.
        if (is_file("{$dir}/Dockerfile.dockerignore")) {
            copy("{$dir}/Dockerfile.dockerignore", "{$dir}/".self::DECLARED_DOCKERFILE.'.dockerignore');
        }

        return self::DECLARED_DOCKERFILE;
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
    private static function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode(file_get_contents($path), true);

        if (! is_array($data)) {
            throw new RuntimeException(basename($path).' is not valid JSON.');
        }

        return $data;
    }
}
