<?php

namespace App\Domains\Builds;

use Composer\Semver\VersionParser;
use RuntimeException;
use UnexpectedValueException;

/** Turns the Python or Ruby version that a project asks for into the tag of an official image. */
final class RuntimeVersion
{
    /**
     * A full version x.y.z picks that exact image, because uv and Bundler can insist on the exact version. A minor x.y
     * picks its newest patch. A range picks the default when it allows it, else the highest minor that it allows. A
     * range that Composer cannot parse gets the default.
     *
     * @param  list<string>  $minors  the minor versions that have an official image, oldest first
     *
     * @throws RuntimeException when the project allows no listed minor
     */
    public static function tag(string $runtime, string $source, string $requested, array $minors, string $default): string
    {
        if (preg_match('/^(\d+\.\d+)(\.\d+)?$/', $requested, $match) === 1 && in_array($match[1], $minors, true)) {
            return $requested;
        }

        $parser = new VersionParser;

        try {
            // Ruby writes "~> 3.3" and Python "~=3.3" for what Composer writes "~3.3". Composer writes "3.12.*" without "==".
            $constraint = $parser->parseConstraints(preg_replace(['/~[>=]\s*/', '/={2,3}\s*/'], ['~', ''], $requested));
        } catch (UnexpectedValueException) {
            return $default;
        }

        $allowed = array_filter($minors, fn (string $minor) => $constraint->matches($parser->parseConstraints("{$minor}.*")));

        if ($allowed === []) {
            throw new RuntimeException("{$source} requires {$runtime} {$requested}. Opencloud builds with {$runtime} ".implode(', ', $minors).'.');
        }

        return in_array($default, $allowed, true) ? $default : end($allowed);
    }
}
