<?php

namespace App\Build;

use App\Deploy\DeploymentLog;
use App\Deploy\EnvironmentVariables;
use App\Enums\ApplicationType;
use App\Models\Deployment;
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

    /** @throws RuntimeException when the clone or the build fails */
    public function build(Deployment $deployment, DeploymentLog $log): BuildResult
    {
        $environment = $deployment->environment;
        $application = $environment->application;
        $dir = sys_get_temp_dir().'/opencloud-build-'.$deployment->id.'-'.Str::lower(Str::random(10));
        File::makeDirectory($dir, 0700);

        try {
            $log->write("Cloning {$application->repository} at {$deployment->commit_sha}.");
            Git::checkout($application->cloneUrl(), $deployment->commit_sha, $dir);

            $buildEnv = EnvironmentVariables::build($environment);
            [$type, $dockerfile] = $this->prepare($dir, array_keys($buildEnv), $environment->build_command);
            $image = self::imageReference($deployment);
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

            return new BuildResult($image, $type);
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
     * @param  list<string>  $secrets
     * @return array{0: ApplicationType, 1: string} the app type and the Dockerfile name
     */
    private function prepare(string $dir, array $secrets, ?string $buildCommand): array
    {
        $composer = self::readJson("{$dir}/composer.json");

        if (isset($composer['require']['laravel/framework'])) {
            $text = DockerfileTemplate::fromConfig()->render($composer, self::readJson("{$dir}/package.json"), $secrets, $buildCommand);
            file_put_contents("{$dir}/".self::GENERATED_DOCKERFILE, $text);
            file_put_contents("{$dir}/".self::GENERATED_DOCKERFILE.'.dockerignore', self::LARAVEL_IGNORE);

            return [ApplicationType::Laravel, self::GENERATED_DOCKERFILE];
        }

        if (! is_file("{$dir}/Dockerfile")) {
            throw new RuntimeException('The repository has no Dockerfile at its root, and its composer.json does not require laravel/framework.');
        }

        if ($secrets === []) {
            return [ApplicationType::Dockerfile, 'Dockerfile'];
        }

        file_put_contents("{$dir}/".self::DECLARED_DOCKERFILE, self::declareInEachStage(file_get_contents("{$dir}/Dockerfile"), self::BUILD_ENV_ARG));

        // BuildKit reads "<dockerfile>.dockerignore" before ".dockerignore", so a Dockerfile-specific ignore file must move with it.
        if (is_file("{$dir}/Dockerfile.dockerignore")) {
            copy("{$dir}/Dockerfile.dockerignore", "{$dir}/".self::DECLARED_DOCKERFILE.'.dockerignore');
        }

        return [ApplicationType::Dockerfile, self::DECLARED_DOCKERFILE];
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
