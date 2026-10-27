<?php

namespace App\Domains\Applications\Enums;

/**
 * The preset of an application: the defaults for one kind of app. Builder::prepare() picks the Dockerfile by the
 * preset. Every other choice of a preset is a method here.
 */
enum ApplicationType: string
{
    case Laravel = 'laravel';
    case Node = 'node';
    case Python = 'python';
    case Ruby = 'ruby';
    case Static = 'static';
    case Dockerfile = 'dockerfile';

    public function defaultPort(): int
    {
        return match ($this) {
            self::Laravel, self::Static, self::Dockerfile => 8080,
            self::Node => 3000,
            self::Python => 8000,
            self::Ruby => 3000,
        };
    }

    /** The generated Laravel and static images always listen on 8080. A Node, Python, or Ruby app listens on PORT, and a Dockerfile app where its Dockerfile says. */
    public function choosesPort(): bool
    {
        return match ($this) {
            self::Laravel, self::Static => false,
            self::Node, self::Python, self::Ruby, self::Dockerfile => true,
        };
    }

    /** Laravel answers /up without the web middleware. */
    public function healthPath(): string
    {
        return match ($this) {
            self::Laravel => '/up',
            self::Node, self::Python, self::Ruby, self::Static, self::Dockerfile => '/',
        };
    }

    /** @return list<array{name: string, command: string, instances: int, exclusive: bool}> */
    public function processes(): array
    {
        return match ($this) {
            self::Laravel => [
                ['name' => 'worker', 'command' => 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600', 'instances' => 1, 'exclusive' => false],
                ['name' => 'scheduler', 'command' => 'php artisan schedule:work', 'instances' => 1, 'exclusive' => true],
            ],
            self::Node, self::Python, self::Ruby, self::Static, self::Dockerfile => [],
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
            self::Laravel => ['php artisan migrate --force'],
            self::Python => ['if [ -f manage.py ]; then python manage.py migrate --noinput; fi'],
            self::Ruby => ['if [ -f bin/rails ]; then bin/rails db:prepare; fi'],
            self::Node, self::Static, self::Dockerfile => [],
        };
    }

    /** The Laravel, Node, Python, and Ruby images run the build command after the build. A static site builds with its build script. */
    public function usesBuildCommand(): bool
    {
        return match ($this) {
            self::Laravel, self::Node, self::Python, self::Ruby => true,
            self::Static, self::Dockerfile => false,
        };
    }

    /** The image runs tini as its entrypoint, so a process passes only its arguments. */
    public function imageRunsTini(): bool
    {
        return match ($this) {
            self::Node, self::Python, self::Ruby => true,
            self::Laravel, self::Static, self::Dockerfile => false,
        };
    }

    /** @return 'mysql'|'none' */
    public function database(): string
    {
        return match ($this) {
            self::Laravel => 'mysql',
            self::Node, self::Python, self::Ruby, self::Static, self::Dockerfile => 'none',
        };
    }

    public function envProfile(): EnvProfileKind
    {
        return match ($this) {
            self::Laravel => EnvProfileKind::Laravel,
            self::Node, self::Python, self::Ruby, self::Static, self::Dockerfile => EnvProfileKind::Generic,
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
            self::Laravel => ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'LOG_CHANNEL' => 'stderr', ...$url],
            self::Node => ['NODE_ENV' => 'production', ...$port, ...$url],
            self::Python => [
                'PYTHONUNBUFFERED' => '1', 'DEBUG' => 'false', ...$port, ...$url,
                ...($hostnames === [] ? [] : ['ALLOWED_HOSTS' => implode(',', $hostnames)]),
            ],
            self::Ruby => [
                'RACK_ENV' => 'production', 'RAILS_ENV' => 'production', 'RAILS_LOG_TO_STDOUT' => '1', 'RAILS_SERVE_STATIC_FILES' => '1',
                ...$port, ...$url,
            ],
            self::Static, self::Dockerfile => [...$port, ...$url],
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
            self::Laravel => ['APP_KEY' => 'base64:'.base64_encode(random_bytes(32))],
            self::Python => ['SECRET_KEY' => bin2hex(random_bytes(32))],
            self::Ruby => ['SECRET_KEY_BASE' => bin2hex(random_bytes(64))],
            self::Node, self::Static, self::Dockerfile => [],
        };
    }
}
