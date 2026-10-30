<?php

namespace App\Domains\Builds;

use Closure;
use RuntimeException;

/**
 * The files of a cloned repository, read when a preset asks for them. A symbolic link of the repository can point
 * anywhere in the builder container, for example at the service account token, so a path that a link puts outside the clone is refused.
 */
final readonly class RepositoryFiles
{
    /** @param  Closure(string): ?string  $read  the contents of a path relative to the root, or null when no such file exists */
    public function __construct(private Closure $read) {}

    /** @throws RuntimeException when a link puts the path outside $dir */
    public static function in(string $dir): self
    {
        $root = realpath($dir).'/';

        return new self(function (string $path) use ($dir, $root) {
            if (! is_file("{$dir}/{$path}")) {
                return null;
            }

            if (! str_starts_with(realpath("{$dir}/{$path}"), $root)) {
                throw new RuntimeException("The path {$path} in the repository leads out of the repository through a symbolic link. Opencloud does not follow such a link. Put the file itself in the repository.");
            }

            return file_get_contents("{$dir}/{$path}");
        });
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
