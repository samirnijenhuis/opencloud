<?php

namespace App\Build;

use Composer\Semver\VersionParser;
use RuntimeException;
use UnexpectedValueException;

/**
 * The package.json of a repository and its lock file. The lock file picks the package manager.
 */
final readonly class NodeProject
{
    /** The Node majors that have an official slim image. */
    public const NODE_VERSIONS = ['18', '20', '22', '24', '26'];

    /** The active LTS. */
    public const DEFAULT_NODE = '24';

    /** When a repository has more than one lock file, the first one wins. */
    public const LOCK_FILES = ['pnpm-lock.yaml', 'yarn.lock', 'package-lock.json'];

    /** @param  array<string, mixed>  $package  the decoded package.json */
    public function __construct(private array $package, private ?string $lockFile) {}

    /**
     * The default when engines.node allows it, else the highest version that it allows. A range that is not semver
     * gets the default.
     *
     * @throws RuntimeException when engines.node allows no listed version
     */
    public function nodeVersion(): string
    {
        $required = $this->package['engines']['node'] ?? null;

        if (! is_string($required)) {
            return self::DEFAULT_NODE;
        }

        $parser = new VersionParser;

        try {
            $constraint = $parser->parseConstraints($required);
        } catch (UnexpectedValueException) {
            return self::DEFAULT_NODE;
        }

        $allowed = array_filter(self::NODE_VERSIONS, fn (string $major) => $constraint->matches($parser->parseConstraints("{$major}.*")));

        if ($allowed === []) {
            throw new RuntimeException("package.json requires Node {$required}. Opencloud builds with Node ".implode(', ', self::NODE_VERSIONS).'.');
        }

        return in_array(self::DEFAULT_NODE, $allowed, true) ? self::DEFAULT_NODE : end($allowed);
    }

    public function hasScript(string $name): bool
    {
        return is_string($this->package['scripts'][$name] ?? null);
    }

    /** The shell command that installs the dependencies, with the dev dependencies, because the build needs them. */
    public function install(): string
    {
        return match ($this->lockFile) {
            'pnpm-lock.yaml' => 'npm install -g $NPM_FLAGS "pnpm@'.self::pnpmVersion($this->package).'" && pnpm install --frozen-lockfile',
            'yarn.lock' => 'npm install -g $NPM_FLAGS corepack && corepack enable && yarn install',
            'package-lock.json' => 'npm ci $NPM_FLAGS',
            null => 'npm install $NPM_FLAGS',
        };
    }

    public function run(string $script): string
    {
        return "{$this->manager()} run {$script}";
    }

    /**
     * The start script, or node with the main file.
     *
     * @return list<string>
     *
     * @throws RepositoryDoesNotFit when package.json has neither
     */
    public function start(): array
    {
        if ($this->hasScript('start')) {
            return [$this->manager(), 'run', 'start'];
        }

        if (is_string($this->package['main'] ?? null)) {
            return ['node', $this->package['main']];
        }

        throw new RepositoryDoesNotFit('package.json has no start script and no main, so opencloud does not know how to start the app. Add a start script.');
    }

    /**
     * pnpm stops when the project's engines.pnpm does not match its own version, so the build installs the version the project asks for.
     *
     * @param  array<string, mixed>  $package
     */
    public static function pnpmVersion(array $package): string
    {
        $manager = $package['packageManager'] ?? null;

        if (is_string($manager) && preg_match('/^pnpm@([^+\s]+)/', $manager, $match) === 1) {
            return $match[1];
        }

        $engine = $package['engines']['pnpm'] ?? null;

        return is_string($engine) && preg_match('/^[\w.^~<>=| -]+$/', $engine) === 1 ? trim($engine) : 'latest';
    }

    private function manager(): string
    {
        return match ($this->lockFile) {
            'pnpm-lock.yaml' => 'pnpm',
            'yarn.lock' => 'yarn',
            default => 'npm',
        };
    }
}
