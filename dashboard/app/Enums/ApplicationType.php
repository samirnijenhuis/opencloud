<?php

namespace App\Enums;

use App\Presets\EnvProfileKind;

/**
 * The preset of an application: the defaults for one kind of app. The builder picks the Dockerfile by the preset.
 * Every other choice of a preset is a method here, so a new preset is one case and one Dockerfile template.
 */
enum ApplicationType: string
{
    case Laravel = 'laravel';
    case Dockerfile = 'dockerfile';

    public function defaultPort(): int
    {
        return 8080;
    }

    /** A generated Laravel image always listens on 8080. A Dockerfile app listens where its Dockerfile says. */
    public function choosesPort(): bool
    {
        return match ($this) {
            self::Laravel => false,
            self::Dockerfile => true,
        };
    }

    /** Laravel answers /up without the web middleware. */
    public function healthPath(): string
    {
        return match ($this) {
            self::Laravel => '/up',
            self::Dockerfile => '/',
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
            self::Dockerfile => [],
        };
    }

    /** @return list<string> */
    public function deployCommands(): array
    {
        return match ($this) {
            self::Laravel => ['php artisan migrate --force'],
            self::Dockerfile => [],
        };
    }

    /** A generated Dockerfile runs the build command after the build. A Dockerfile app builds only what its Dockerfile says. */
    public function usesBuildCommand(): bool
    {
        return match ($this) {
            self::Laravel => true,
            self::Dockerfile => false,
        };
    }

    /** @return 'mysql'|'none' */
    public function database(): string
    {
        return match ($this) {
            self::Laravel => 'mysql',
            self::Dockerfile => 'none',
        };
    }

    public function envProfile(): EnvProfileKind
    {
        return match ($this) {
            self::Laravel => EnvProfileKind::Laravel,
            self::Dockerfile => EnvProfileKind::Generic,
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
            self::Dockerfile => ['PORT' => (string) $port, ...$url],
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
            self::Dockerfile => [],
        };
    }
}
