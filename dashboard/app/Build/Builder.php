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
                ->start(self::buildctl($dir, $dockerfile, $image, array_keys($buildEnv)))
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
    public static function buildctl(string $dir, string $dockerfile, string $image, array $secrets): array
    {
        return [
            'buildctl', '--addr', config('opencloud.buildkit_addr'),
            'build', '--progress=plain', '--frontend', 'dockerfile.v0',
            '--local', "context={$dir}", '--local', "dockerfile={$dir}", '--opt', "filename={$dockerfile}",
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

        return [ApplicationType::Dockerfile, 'Dockerfile'];
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
