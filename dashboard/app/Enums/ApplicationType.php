<?php

namespace App\Enums;

use App\Presets\EnvProfileKind;

/**
 * The preset of an application: the defaults for one kind of app. Builder::prepare() picks the Dockerfile by the
 * preset. Every other choice of a preset is a method here.
 */
enum ApplicationType: string
{
    case Laravel = 'laravel';
    case Node = 'node';
    case Static = 'static';
    case Dockerfile = 'dockerfile';

    public function defaultPort(): int
    {
        return match ($this) {
            self::Laravel, self::Static, self::Dockerfile => 8080,
            self::Node => 3000,
        };
    }

    /** The generated Laravel and static images always listen on 8080. A Node app listens on PORT, and a Dockerfile app where its Dockerfile says. */
    public function choosesPort(): bool
    {
        return match ($this) {
            self::Laravel, self::Static => false,
            self::Node, self::Dockerfile => true,
        };
    }

    /** Laravel answers /up without the web middleware. */
    public function healthPath(): string
    {
        return match ($this) {
            self::Laravel => '/up',
            self::Node, self::Static, self::Dockerfile => '/',
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
            self::Node, self::Static, self::Dockerfile => [],
        };
    }

    /** @return list<string> */
    public function deployCommands(): array
    {
        return match ($this) {
            self::Laravel => ['php artisan migrate --force'],
            self::Node, self::Static, self::Dockerfile => [],
        };
    }

    /** The Laravel and Node images run the build command after the build. A static site builds with its build script. */
    public function usesBuildCommand(): bool
    {
        return match ($this) {
            self::Laravel, self::Node => true,
            self::Static, self::Dockerfile => false,
        };
    }

    /** @return 'mysql'|'none' */
    public function database(): string
    {
        return match ($this) {
            self::Laravel => 'mysql',
            self::Node, self::Static, self::Dockerfile => 'none',
        };
    }

    public function envProfile(): EnvProfileKind
    {
        return match ($this) {
            self::Laravel => EnvProfileKind::Laravel,
            self::Node, self::Static, self::Dockerfile => EnvProfileKind::Generic,
        };
    }

    /**
     * The env values that opencloud sets. A datastore value or a user value with the same key wins.
     *
     * @return array<string, string>
     */
    public function systemEnv(int $port, ?string $url): array
    {
        $url = $url === null ? [] : ['APP_URL' => $url];

        return match ($this) {
            self::Laravel => ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'LOG_CHANNEL' => 'stderr', ...$url],
            self::Node => ['NODE_ENV' => 'production', 'PORT' => (string) $port, ...$url],
            self::Static, self::Dockerfile => ['PORT' => (string) $port, ...$url],
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
            self::Node, self::Static, self::Dockerfile => [],
        };
    }
}
