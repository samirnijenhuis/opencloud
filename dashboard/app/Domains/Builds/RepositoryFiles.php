<?php

namespace App\Domains\Builds;

use Closure;

/** The files of a cloned repository, read when a preset asks for them. */
final readonly class RepositoryFiles
{
    /** @param  Closure(string): ?string  $read  the contents of a path relative to the root, or null when no such file exists */
    public function __construct(private Closure $read) {}

    public static function in(string $dir): self
    {
        return new self(fn (string $path) => is_file("{$dir}/{$path}") ? file_get_contents("{$dir}/{$path}") : null);
    }

    public function read(string $path): ?string
    {
        return ($this->read)($path);
    }

    public function has(string $path): bool
    {
        return $this->read($path) !== null;
    }

    /** The command of the first web line of the Procfile. A Python or Ruby app starts with it when it has one. */
    public function procfileWeb(): ?string
    {
        return preg_match('/^web:[ \t]*(\S.*?)\s*$/m', (string) $this->read('Procfile'), $match) === 1 ? $match[1] : null;
    }

    /**
     * Whether one of the files names the package as a whole word. A lock file names every package, also the indirect ones.
     *
     * @param  list<string>  $paths
     */
    public function mention(array $paths, string $package): bool
    {
        $text = implode("\n", array_map(fn (string $path) => (string) $this->read($path), $paths));

        return preg_match('/(?<![\w.-])'.preg_quote($package, '/').'(?![\w-])/i', $text) === 1;
    }
}
