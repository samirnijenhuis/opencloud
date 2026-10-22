<?php

namespace App\Build;

use Composer\Semver\VersionParser;
use RuntimeException;

/**
 * Turns composer.json and package.json of a Laravel app into Dockerfile text. It reads only its own template file.
 */
final readonly class DockerfileTemplate
{
    public const DEFAULT_PHP = '8.4';

    /** The template installs these in every image. */
    public const BASE_EXTENSIONS = ['bcmath', 'exif', 'gd', 'intl', 'opcache', 'pcntl', 'pdo_mysql', 'pdo_pgsql', 'redis', 'zip'];

    /** The official PHP image, which FrankenPHP builds on, compiles these in. */
    private const BUILT_IN_EXTENSIONS = [
        'core', 'ctype', 'curl', 'date', 'dom', 'fileinfo', 'filter', 'hash', 'iconv', 'json', 'libxml', 'mbstring',
        'mysqlnd', 'openssl', 'pcre', 'pdo', 'pdo_sqlite', 'phar', 'posix', 'random', 'readline', 'reflection',
        'session', 'simplexml', 'sodium', 'spl', 'sqlite3', 'standard', 'tokenizer', 'xml', 'xmlreader', 'xmlwriter', 'zlib',
    ];

    /** Composer names an extension differently from install-php-extensions. */
    private const EXTENSION_ALIASES = ['zend-opcache' => 'opcache', 'zend opcache' => 'opcache'];

    /**
     * @param  list<string>  $phpVersions  the PHP minor versions that have a base image, for example ["8.3", "8.4"]
     * @param  string  $baseImage  the image name with "{php}" in place of the minor version
     */
    public function __construct(private array $phpVersions, private string $baseImage) {}

    public static function fromConfig(): self
    {
        return new self(config('opencloud.php_versions'), config('opencloud.php_base_image'));
    }

    /**
     * @param  array<string, mixed>  $composer  the decoded composer.json
     * @param  array<string, mixed>|null  $package  the decoded package.json, or null when the repository has none
     * @param  list<string>  $buildSecrets  the keys of the build-time env values
     */
    public function render(array $composer, ?array $package, array $buildSecrets = [], ?string $buildCommand = null): string
    {
        $extra = $this->extraExtensions($composer);

        $text = self::sections(file_get_contents(dirname(__DIR__, 2).'/resources/docker/laravel.Dockerfile'), [
            'assets' => isset($package['scripts']['build']),
        ]);

        return self::fill($text, [
            'base_image' => str_replace('{php}', $this->phpVersion($composer), $this->baseImage),
            'base_extensions' => implode(' ', self::BASE_EXTENSIONS),
            'extra_extensions' => $extra === [] ? '' : 'RUN install-php-extensions '.implode(' ', $extra),
            'secret_mounts' => implode('', array_map(fn (string $key) => "--mount=type=secret,id={$key},env={$key} ", $buildSecrets)),
            'build_command' => $buildCommand === null || trim($buildCommand) === '' ? '' : 'RUN '.trim($buildCommand),
        ]);
    }

    /**
     * @param  array<string, mixed>  $composer
     *
     * @throws RuntimeException when no listed PHP version satisfies composer.json
     */
    public function phpVersion(array $composer): string
    {
        $required = $composer['require']['php'] ?? null;

        if (! is_string($required)) {
            return self::DEFAULT_PHP;
        }

        $parser = new VersionParser;
        $constraint = $parser->parseConstraints($required);
        $versions = $this->phpVersions;
        usort($versions, version_compare(...));

        foreach (array_reverse($versions) as $minor) {
            if ($constraint->matches($parser->parseConstraints("{$minor}.*"))) {
                return $minor;
            }
        }

        throw new RuntimeException("composer.json requires PHP {$required}. Opencloud has base images for PHP ".implode(', ', $this->phpVersions).'.');
    }

    /**
     * @param  array<string, mixed>  $composer
     * @return list<string> the required extensions that neither PHP nor the template installs, sorted
     */
    public function extraExtensions(array $composer): array
    {
        $extensions = [];

        foreach (array_keys($composer['require'] ?? []) as $name) {
            $name = strtolower((string) $name);

            if (str_starts_with($name, 'ext-')) {
                $extension = substr($name, 4);
                $extensions[] = self::EXTENSION_ALIASES[$extension] ?? $extension;
            }
        }

        $missing = array_unique(array_diff($extensions, self::BASE_EXTENSIONS, self::BUILT_IN_EXTENSIONS));
        sort($missing);

        return $missing;
    }

    /** @param  array<string, bool>  $flags */
    private static function sections(string $text, array $flags): string
    {
        foreach ($flags as $name => $keep) {
            $pattern = '/^\{\{#'.$name.'\}\}\n(.*?)^\{\{\/'.$name.'\}\}\n/ms';
            $text = preg_replace($pattern, $keep ? '$1' : '', $text);
        }

        return $text;
    }

    /**
     * A placeholder that fills with nothing removes its whole line.
     *
     * @param  array<string, string>  $values
     */
    private static function fill(string $text, array $values): string
    {
        foreach ($values as $name => $value) {
            $placeholder = '{{ '.$name.' }}';
            $text = $value === ''
                ? str_replace(["{$placeholder}\n", $placeholder], '', $text)
                : str_replace($placeholder, $value, $text);
        }

        return $text;
    }
}
