<?php

namespace App\GitHub;

/**
 * The "owner/name" of a github.com repository, in lower case, because GitHub compares names without case.
 */
final class RepositoryName
{
    /** Takes "owner/name" or a github.com URL. Returns null for a repository on another host. */
    public static function parse(string $repository): ?string
    {
        $pattern = '#^(?:https://github\.com/)?([A-Za-z0-9-]+)/([A-Za-z0-9._-]+?)(?:\.git)?/?$#';

        if (preg_match($pattern, trim($repository), $match) !== 1) {
            return null;
        }

        return strtolower("{$match[1]}/{$match[2]}");
    }
}
