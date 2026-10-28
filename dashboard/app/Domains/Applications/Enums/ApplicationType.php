<?php

namespace App\Domains\Applications\Enums;

use App\Domains\Datastores\Env\EnvProfile;
use App\Domains\Datastores\Env\GenericProfile;
use App\Domains\Datastores\Env\LaravelProfile;
use App\Domains\Environments\Enums\ProcessKind;

/**
 * The preset of an application: the defaults for one kind of app. Builder::prepare() picks the Dockerfile by the
 * preset. Every other choice of a preset is a method here.
 */
enum ApplicationType: string
{
    case LARAVEL = 'laravel';
    case NODE = 'node';
    case PYTHON = 'python';
    case RUBY = 'ruby';
    case STATIC = 'static';
    case DOCKERFILE = 'dockerfile';

    public function defaultPort(): int
    {
        return match ($this) {
            self::LARAVEL, self::STATIC, self::DOCKERFILE => 8080,
            self::NODE => 3000,
            self::PYTHON => 8000,
            self::RUBY => 3000,
        };
    }

    /** The generated Laravel and static images always listen on 8080. A Node, Python, or Ruby app listens on PORT, and a Dockerfile app where its Dockerfile says. */
    public function choosesPort(): bool
    {
        return match ($this) {
            self::LARAVEL, self::STATIC => false,
            self::NODE, self::PYTHON, self::RUBY, self::DOCKERFILE => true,
        };
    }

    /** Laravel answers /up without the web middleware. */
    public function healthPath(): string
    {
        return match ($this) {
            self::LARAVEL => '/up',
            self::NODE, self::PYTHON, self::RUBY, self::STATIC, self::DOCKERFILE => '/',
        };
    }

    /**
     * The scheduler runs schedule:work in one long-running process, as Laravel Cloud runs it in its app instances.
     *
     * @return list<array{name: string, kind: ProcessKind, command: string, schedule: ?string}>
     */
    public function processes(): array
    {
        return match ($this) {
            self::LARAVEL => [
                ['name' => 'worker', 'kind' => ProcessKind::LONG_RUNNING, 'command' => 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600', 'schedule' => null],
                ['name' => 'scheduler', 'kind' => ProcessKind::LONG_RUNNING, 'command' => 'php artisan schedule:work', 'schedule' => null],
            ],
            self::NODE, self::PYTHON, self::RUBY, self::STATIC, self::DOCKERFILE => [],
        };
    }

    /**
     * The preset is chosen before opencloud reads the repository, so a command for one framework checks for it first.
     *
     * @return list<string>
     */
    public function deployCommands(): array
    {
        return match ($this) {
            self::LARAVEL => ['php artisan migrate --force'],
            self::PYTHON => ['if [ -f manage.py ]; then python manage.py migrate --noinput; fi'],
            self::RUBY => ['if [ -f bin/rails ]; then bin/rails db:prepare; fi'],
            self::NODE, self::STATIC, self::DOCKERFILE => [],
        };
    }

    /** The Laravel, Node, Python, and Ruby images run the build command after the build. A static site builds with its build script. */
    public function usesBuildCommand(): bool
    {
        return match ($this) {
            self::LARAVEL, self::NODE, self::PYTHON, self::RUBY => true,
            self::STATIC, self::DOCKERFILE => false,
        };
    }

    /** The image runs tini as its entrypoint, so a process passes only its arguments. */
    public function imageRunsTini(): bool
    {
        return match ($this) {
            self::NODE, self::PYTHON, self::RUBY => true,
            self::LARAVEL, self::STATIC, self::DOCKERFILE => false,
        };
    }

    /** @return 'mysql'|'none' */
    public function database(): string
    {
        return match ($this) {
            self::LARAVEL => 'mysql',
            self::NODE, self::PYTHON, self::RUBY, self::STATIC, self::DOCKERFILE => 'none',
        };
    }

    /**
     * The env names of a database, Valkey, and a bucket, as on Laravel Cloud. The preset decides them, and the user has
     * no choice. Opencloud cannot know the app in a Dockerfile image, so it gets both sets for the same resource. The
     * first profile gives the config line of a database or a bucket that is not the default.
     *
     * @return list<EnvProfile>
     */
    public function envProfiles(): array
    {
        return match ($this) {
            self::LARAVEL => [new LaravelProfile],
            self::NODE, self::PYTHON, self::RUBY, self::STATIC => [new GenericProfile],
            self::DOCKERFILE => [new LaravelProfile, new GenericProfile],
        };
    }

    /**
     * The env values that opencloud sets. A datastore value or a user value with the same key wins. The preset does not
     * know the framework, so every Python app gets the Django values and every Ruby app the Rails values. Other apps
     * ignore them.
     *
     * @param  list<string>  $hostnames  the domains of the environment, oldest first
     * @return array<string, string>
     */
    public function systemEnv(int $port, array $hostnames): array
    {
        $url = $hostnames === [] ? [] : ['APP_URL' => "https://{$hostnames[0]}"];
        $port = ['PORT' => (string) $port];

        return match ($this) {
            self::LARAVEL => ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'LOG_CHANNEL' => 'stderr', ...$url],
            self::NODE => ['NODE_ENV' => 'production', ...$port, ...$url],
            self::PYTHON => [
                'PYTHONUNBUFFERED' => '1', 'DEBUG' => 'false', ...$port, ...$url,
                ...($hostnames === [] ? [] : ['ALLOWED_HOSTS' => implode(',', $hostnames)]),
            ],
            self::RUBY => [
                'RACK_ENV' => 'production', 'RAILS_ENV' => 'production', 'RAILS_LOG_TO_STDOUT' => '1', 'RAILS_SERVE_STATIC_FILES' => '1',
                ...$port, ...$url,
            ],
            self::STATIC, self::DOCKERFILE => [...$port, ...$url],
        };
    }

    /**
     * The secrets that a new environment gets when the user gives none. Production keeps its values to itself, so a
     * preview gets its own.
     *
     * @return array<string, string>
     */
    public function generatedSecrets(): array
    {
        return match ($this) {
            self::LARAVEL => ['APP_KEY' => 'base64:'.base64_encode(random_bytes(32))],
            self::PYTHON => ['SECRET_KEY' => bin2hex(random_bytes(32))],
            self::RUBY => ['SECRET_KEY_BASE' => bin2hex(random_bytes(64))],
            self::NODE, self::STATIC, self::DOCKERFILE => [],
        };
    }
}
