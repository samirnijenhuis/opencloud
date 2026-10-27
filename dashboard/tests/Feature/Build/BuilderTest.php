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
use PHPUnit\Framework\Attributes\DataProvider;
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

        $image = (new Builder)->build($deployment, DeploymentLog::for($deployment));

        $dir = $processes->ran[0]['path'];
        $this->assertSame("registry.opencloud.svc.cluster.local:5000/{$deployment->environment->application_id}/{$deployment->environment_id}:0123456-{$deployment->id}", $image);
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
                '--output', "type=image,name={$image},push=true,registry.insecure=true",
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
            "2026-10-23T12:00:00Z Building {$image} from .opencloud.Dockerfile.\n".
            "2026-10-23T12:00:00Z #1 [internal] load build definition\n".
            "2026-10-23T12:00:00Z #5 composer install with token ********\n".
            "2026-10-23T12:00:00Z #9 DONE 1.2s\n",
            $deployment->fresh()->log,
        );
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function test_the_dockerfile_preset_builds_the_repository_dockerfile_also_in_a_laravel_repository(): void
    {
        $processes = FakeProcesses::install()->withLaravelRepository();
        $processes->repository['Dockerfile'] = "FROM nginx:alpine\n";
        $deployment = $this->deployment('https://git.example.com/acme/site.git', ApplicationType::Dockerfile);

        (new Builder)->build($deployment, DeploymentLog::for($deployment));

        $this->assertSame(['git', 'fetch', '--quiet', '--depth', '1', 'https://git.example.com/acme/site.git', self::SHA], $processes->commands()[1]);
        $this->assertContains('filename=Dockerfile', $processes->commands()[3]);
        $this->assertNotContains('--secret', $processes->commands()[3]);
        $this->assertSame(["FROM nginx:alpine\n"], $processes->dockerfiles);
        $this->assertSame([null], $processes->dockerignores);
        $this->assertContains('build-arg:OPENCLOUD_BUILD_ENV=41fbf08025be968956e2d9ea9146108cd9f1828845becca8f341e623141af7de', $processes->commands()[3]);
    }

    public function test_the_node_preset_writes_a_dockerfile_from_package_json_and_the_lock_file(): void
    {
        $processes = FakeProcesses::install();
        $processes->repository = [
            'package.json' => json_encode(['engines' => ['node' => '22.x'], 'scripts' => ['build' => 'vite build', 'start' => 'node server.js']]),
            'pnpm-lock.yaml' => "lockfileVersion: '9.0'\n",
            'yarn.lock' => "# yarn lockfile v1\n",
        ];
        $deployment = $this->deployment('acme/api', ApplicationType::Node);
        $deployment->environment->update(['build_command' => 'npx prisma generate']);

        (new Builder)->build($deployment, DeploymentLog::for($deployment));

        $this->assertContains('filename=.opencloud.Dockerfile', $processes->commands()[3]);
        $this->assertSame([
            'FROM node:22-slim',
            'RUN apt-get update && apt-get install -y --no-install-recommends tini && rm -rf /var/lib/apt/lists/*',
            'RUN --mount=type=cache,target=/root/.npm --mount=type=cache,target=/root/.local/share/pnpm/store --mount=type=cache,target=/usr/local/share/.cache/yarn npm install -g $NPM_FLAGS "pnpm@latest" && pnpm install --frozen-lockfile',
            'RUN pnpm run build',
            'RUN npx prisma generate',
            'ENTRYPOINT ["/usr/bin/tini", "--"]',
            'CMD ["pnpm", "run", "start"]',
        ], array_values(preg_grep('/^(FROM|RUN|ENTRYPOINT|CMD) /', explode("\n", $processes->dockerfiles[0]))));
        $this->assertSame([".git\nnode_modules\n"], $processes->dockerignores);
    }

    public function test_the_python_preset_writes_a_dockerfile_from_the_dependency_file_and_the_procfile(): void
    {
        $processes = FakeProcesses::install();
        $processes->repository = [
            'requirements.txt' => "flask==3.1\ngunicorn==23.0\n",
            'runtime.txt' => "python-3.9\n",
            'Procfile' => "web: gunicorn shop.wsgi:app --bind 0.0.0.0:\$PORT\n",
            'package.json' => '{"scripts":{"start":"node server.js"}}',
        ];
        $deployment = $this->deployment('acme/shop', ApplicationType::Python);
        $deployment->environment->update(['build_command' => 'flask --app shop assets build']);
        EnvVar::factory()->for($deployment->environment)->create(['key' => 'PIP_INDEX_URL', 'value' => 'https://pypi.example.com/simple', 'available_at' => EnvVarAvailability::Build]);

        (new Builder)->build($deployment, DeploymentLog::for($deployment));

        $this->assertContains('filename=.opencloud.Dockerfile', $processes->commands()[3]);
        $this->assertContains('id=PIP_INDEX_URL,env=PIP_INDEX_URL', $processes->commands()[3]);
        $this->assertSame([
            'FROM python:3.14',
            'RUN apt-get update && apt-get install -y --no-install-recommends tini && rm -rf /var/lib/apt/lists/*',
            'RUN python -m venv /opt/venv',
            'RUN --mount=type=cache,target=/root/.cache --mount=type=secret,id=PIP_INDEX_URL,env=PIP_INDEX_URL pip install -r requirements.txt',
            'RUN --mount=type=secret,id=PIP_INDEX_URL,env=PIP_INDEX_URL flask --app shop assets build',
            'ENTRYPOINT ["/usr/bin/tini", "--"]',
            'CMD ["sh", "-c", "exec gunicorn shop.wsgi:app --bind 0.0.0.0:$PORT"]',
        ], array_values(preg_grep('/^(FROM|RUN|ENTRYPOINT|CMD) /', explode("\n", $processes->dockerfiles[0]))));
        $this->assertSame([".git\n.venv\n"], $processes->dockerignores);
    }

    public function test_the_static_preset_serves_a_repository_without_package_json_and_ignores_the_build_command(): void
    {
        $processes = FakeProcesses::install();
        $processes->repository = ['index.html' => '<h1>Docs</h1>', 'composer.json' => '{"require":{"laravel/framework":"^12.0"}}'];
        $deployment = $this->deployment('acme/docs', ApplicationType::Static);
        $deployment->environment->update(['build_command' => 'make site']);

        (new Builder)->build($deployment, DeploymentLog::for($deployment));

        $this->assertSame(['FROM nginx:1.30-alpine', 'COPY . /site'], array_values(preg_grep('/^(FROM|RUN|CMD|COPY) [^<]/', explode("\n", $processes->dockerfiles[0]))));
        $this->assertSame([".git\nnode_modules\n"], $processes->dockerignores);
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

    /** @return array<string, array{ApplicationType, array<string, string>, string}> */
    public static function repositoriesThatDoNotFit(): array
    {
        return [
            'laravel without laravel/framework' => [
                ApplicationType::Laravel,
                ['composer.json' => '{"require":{"symfony/console":"^7"}}', 'Dockerfile' => "FROM php:8.4-cli\n"],
                'The application uses the Laravel preset, but the composer.json of the repository does not require laravel/framework. To use another preset, create a new application with the same repository.',
            ],
            'laravel without composer.json' => [
                ApplicationType::Laravel,
                ['Dockerfile' => "FROM nginx:alpine\n"],
                'The application uses the Laravel preset, but the composer.json of the repository does not require laravel/framework. To use another preset, create a new application with the same repository.',
            ],
            'node without package.json' => [
                ApplicationType::Node,
                ['Dockerfile' => "FROM node:24\n"],
                'The application uses the Node.js preset, but the repository has no package.json at its root. To use another preset, create a new application with the same repository.',
            ],
            'node without a way to start' => [
                ApplicationType::Node,
                ['package.json' => '{"scripts":{"build":"tsc"}}'],
                'package.json has no start script and no main, so opencloud does not know how to start the app. Add a start script. To use another preset, create a new application with the same repository.',
            ],
            'python without a dependency file' => [
                ApplicationType::Python,
                ['app.py' => "app = Flask(__name__)\n", 'package.json' => '{"scripts":{"start":"node server.js"}}'],
                'The application uses the Python preset, but the repository has no uv.lock, poetry.lock, Pipfile.lock, requirements.txt, or pyproject.toml at its root. To use another preset, create a new application with the same repository.',
            ],
            'dockerfile without a Dockerfile' => [
                ApplicationType::Dockerfile,
                ['composer.json' => '{"require":{"laravel/framework":"^12.0"}}'],
                'The application uses the Dockerfile preset, but the repository has no Dockerfile at its root. To use another preset, create a new application with the same repository.',
            ],
        ];
    }

    /** @param  array<string, string>  $repository */
    #[DataProvider('repositoriesThatDoNotFit')]
    public function test_a_repository_that_does_not_fit_the_stored_preset_fails_before_buildctl(ApplicationType $type, array $repository, string $message): void
    {
        $processes = FakeProcesses::install();
        $processes->repository = $repository;
        $deployment = $this->deployment('acme/app', $type);

        try {
            (new Builder)->build($deployment, DeploymentLog::for($deployment));
            $this->fail('The build did not fail.');
        } catch (RuntimeException $e) {
            $this->assertSame($message, $e->getMessage());
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
