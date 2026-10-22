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
            ['git', 'remote', 'add', 'origin', 'https://github.com/acme/shop.git'],
            ['git', 'fetch', '--quiet', '--depth', '1', 'origin', self::SHA],
            ['git', 'checkout', '--quiet', 'FETCH_HEAD'],
            [
                'buildctl', '--addr', 'tcp://buildkitd.opencloud.svc.cluster.local:1234',
                'build', '--progress=plain', '--frontend', 'dockerfile.v0',
                '--local', "context={$dir}", '--local', "dockerfile={$dir}", '--opt', 'filename=.opencloud.Dockerfile',
                '--secret', 'id=COMPOSER_AUTH,env=COMPOSER_AUTH',
                '--output', "type=image,name={$result->image},push=true,registry.insecure=true",
            ],
        ], $processes->commands());
        $this->assertSame([$dir, $dir, $dir, $dir, $dir], array_column($processes->ran, 'path'));
        $this->assertSame(['COMPOSER_AUTH' => 'ghp_secret_token'], $processes->ran[4]['env']);
        $this->assertStringContainsString(
            "RUN --mount=type=secret,id=COMPOSER_AUTH,env=COMPOSER_AUTH composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist\n\nFROM node:22-alpine AS assets\n",
            $processes->dockerfiles[0],
        );
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
        $this->assertSame(['git', 'remote', 'add', 'origin', 'https://git.example.com/acme/site.git'], $processes->commands()[1]);
        $this->assertContains('filename=Dockerfile', $processes->commands()[4]);
        $this->assertNotContains('--secret', $processes->commands()[4]);
        $this->assertSame(["FROM nginx:alpine\n"], $processes->dockerfiles);
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

        $this->assertSame(['git', 'git', 'git', 'git'], array_column($processes->commands(), 0));
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
