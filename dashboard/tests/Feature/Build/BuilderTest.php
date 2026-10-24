<?php

namespace Tests\Feature\Build;

use App\Build\Builder;
use App\Deploy\DeploymentLog;
use App\Enums\ApplicationType;
use App\Enums\EnvVarAvailability;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\EnvVar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\Fakes\FakeProcesses;
use Tests\TestCase;

class BuilderTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private const APP_KEY = 'base64:a2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2s=';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => self::APP_KEY]);
    }

    public function test_a_laravel_app_is_cloned_at_the_commit_and_built_with_the_generated_dockerfile(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $processes = FakeProcesses::install()->withLaravelRepository();
        $processes->buildOutput = ['#1 [internal] load build definition', '#5 composer install with token ghp_secret_token', '#9 DONE 1.2s'];
        $deployment = $this->deployment('acme/shop');
        EnvVar::factory()->for($deployment->environment)->create([
            'key' => 'COMPOSER_AUTH', 'value' => 'ghp_secret_token', 'is_secret' => true, 'available_at' => EnvVarAvailability::Build,
        ]);
        EnvVar::factory()->for($deployment->environment)->create(['key' => 'DB_PASSWORD', 'value' => 'runtime-only', 'is_secret' => true]);

        $result = (new Builder)->build($deployment, DeploymentLog::for($deployment));

        $dir = $processes->ran[0]['path'];
        $this->assertSame(ApplicationType::Laravel, $result->type);
        $this->assertSame("registry.opencloud.svc.cluster.local:5000/{$deployment->environment->application_id}/{$deployment->environment_id}:0123456-{$deployment->id}", $result->image);
        $this->assertSame([
            ['git', 'init', '--quiet'],
            ['git', 'fetch', '--quiet', '--depth', '1', 'https://github.com/acme/shop.git', self::SHA],
            ['git', 'checkout', '--quiet', 'FETCH_HEAD'],
            [
                'buildctl', '--addr', 'kube-pod://buildkitd-test?namespace=opencloud',
                'build', '--progress=plain', '--frontend', 'dockerfile.v0',
                '--local', "context={$dir}", '--local', "dockerfile={$dir}", '--opt', 'filename=.opencloud.Dockerfile',
                '--opt', 'build-arg:OPENCLOUD_BUILD_ENV=a41cb0306208220797c400e27ec48dac355d1011defd7fc63dd02a8f0eb02ef6',
                '--secret', 'id=COMPOSER_AUTH,env=COMPOSER_AUTH',
                '--output', "type=image,name={$result->image},push=true,registry.insecure=true",
            ],
        ], $processes->commands());
        $this->assertSame([$dir, $dir, $dir, $dir], array_column($processes->ran, 'path'));
        $this->assertSame(['COMPOSER_AUTH' => 'ghp_secret_token'], $processes->ran[3]['env']);
        $this->assertStringContainsString(
            "RUN --mount=type=cache,target=/tmp/composer-cache --mount=type=secret,id=COMPOSER_AUTH,env=COMPOSER_AUTH composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist\n\n# Vite plugins such as Wayfinder run artisan during the asset build, so this stage has PHP and Node.\nFROM base AS assets\nARG OPENCLOUD_BUILD_ENV\n",
            $processes->dockerfiles[0],
        );
        $this->assertStringContainsString("FROM base AS vendor\n# Opencloud sets this to a key of the build values, so a build with other values never reuses a layer that read a secret.\nARG OPENCLOUD_BUILD_ENV\nCOPY . .\n", $processes->dockerfiles[0]);
        $this->assertSame(
            '2026-10-23T12:00:00Z Cloning acme/shop at '.self::SHA.".\n".
            "2026-10-23T12:00:00Z Building {$result->image} from .opencloud.Dockerfile.\n".
            "2026-10-23T12:00:00Z #1 [internal] load build definition\n".
            "2026-10-23T12:00:00Z #5 composer install with token ********\n".
            "2026-10-23T12:00:00Z #9 DONE 1.2s\n",
            $deployment->fresh()->log,
        );
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function test_a_repository_without_laravel_builds_its_own_dockerfile(): void
    {
        $processes = FakeProcesses::install();
        $processes->repository = ['Dockerfile' => "FROM nginx:alpine\n", 'package.json' => '{}'];
        $deployment = $this->deployment('https://git.example.com/acme/site.git', ApplicationType::Laravel);

        $result = (new Builder)->build($deployment, DeploymentLog::for($deployment));

        $this->assertSame(ApplicationType::Dockerfile, $result->type);
        $this->assertSame(['git', 'fetch', '--quiet', '--depth', '1', 'https://git.example.com/acme/site.git', self::SHA], $processes->commands()[1]);
        $this->assertContains('filename=Dockerfile', $processes->commands()[3]);
        $this->assertNotContains('--secret', $processes->commands()[3]);
        $this->assertSame(["FROM nginx:alpine\n"], $processes->dockerfiles);
        $this->assertContains('build-arg:OPENCLOUD_BUILD_ENV=41fbf08025be968956e2d9ea9146108cd9f1828845becca8f341e623141af7de', $processes->commands()[3]);
    }

    public function test_a_dockerfile_app_with_build_values_gets_a_cache_key_for_each_set_of_values(): void
    {
        $dockerfile = "# syntax=docker/dockerfile:1\nFROM node:22 AS assets\nRUN --mount=type=secret,id=VITE_API npm run build\n\nFROM nginx:alpine\nCOPY --from=assets /app/dist /usr/share/nginx/html\n";
        $keys = [];

        foreach (['https://api.example.com', 'https://staging.example.com'] as $value) {
            $processes = FakeProcesses::install();
            $processes->repository = ['Dockerfile' => $dockerfile, 'Dockerfile.dockerignore' => "node_modules\n"];
            $deployment = $this->deployment('https://git.example.com/acme/site.git', ApplicationType::Dockerfile);
            EnvVar::factory()->for($deployment->environment)->create(['key' => 'VITE_API', 'value' => $value, 'available_at' => EnvVarAvailability::Build]);

            (new Builder)->build($deployment, DeploymentLog::for($deployment));

            $command = $processes->commands()[3];
            $this->assertContains('filename=.opencloud.repository.Dockerfile', $command);
            $keys[] = collect($command)->first(fn (string $arg) => str_starts_with($arg, 'build-arg:'));
            $this->assertSame(
                ["# syntax=docker/dockerfile:1\nFROM node:22 AS assets\nARG OPENCLOUD_BUILD_ENV\nRUN --mount=type=secret,id=VITE_API npm run build\n\nFROM nginx:alpine\nARG OPENCLOUD_BUILD_ENV\nCOPY --from=assets /app/dist /usr/share/nginx/html\n"],
                $processes->dockerfiles,
            );
            $this->assertSame(["node_modules\n"], $processes->dockerignores);
        }

        $this->assertSame([
            'build-arg:OPENCLOUD_BUILD_ENV=38b6d4f823826fbdb1422ff9f8a6f1273c4b8faa991a7f07050715cd641f026d',
            'build-arg:OPENCLOUD_BUILD_ENV=9873117b6bcb48afcf787f8a2ef3f50329c076e61520a5bf81ed23d4795d3145',
        ], $keys);
    }

    public function test_a_repository_without_laravel_and_without_a_dockerfile_fails_before_buildctl(): void
    {
        $processes = FakeProcesses::install();
        $processes->repository = ['composer.json' => '{"require":{"symfony/console":"^7"}}'];
        $deployment = $this->deployment('acme/cli');

        try {
            (new Builder)->build($deployment, DeploymentLog::for($deployment));
            $this->fail('The build did not fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('The repository has no Dockerfile at its root, and its composer.json does not require laravel/framework.', $e->getMessage());
        }

        $this->assertSame(['git', 'git', 'git'], array_column($processes->commands(), 0));
        $this->assertDirectoryDoesNotExist($processes->ran[0]['path']);
    }

    public function test_a_failed_buildctl_throws_after_the_output_is_logged(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $processes = FakeProcesses::install()->withLaravelRepository();
        $processes->buildOutput = ['ERROR: failed to solve: process "/bin/sh -c composer install" did not complete successfully'];
        $processes->buildExitCode = 1;
        $deployment = $this->deployment('acme/shop');

        try {
            (new Builder)->build($deployment, DeploymentLog::for($deployment));
            $this->fail('The build did not fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('buildctl exited with code 1.', $e->getMessage());
        }

        $this->assertStringEndsWith(
            "2026-10-23T12:00:00Z ERROR: failed to solve: process \"/bin/sh -c composer install\" did not complete successfully\n",
            $deployment->fresh()->log,
        );
    }

    private function deployment(string $repository, ApplicationType $type = ApplicationType::Laravel): Deployment
    {
        $application = Application::factory()->create(['repository' => $repository, 'type' => $type]);
        $environment = Environment::factory()->for($application)->create();

        return Deployment::factory()->for($environment)->create(['commit_sha' => self::SHA]);
    }
}
