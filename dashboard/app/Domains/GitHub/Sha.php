<?php

namespace App\Domains\GitHub;

final class Sha
{
    /** GitHub sends forty zeros as the "after" commit of a deleted branch. */
    public static function valid(string $sha): bool
    {
        return preg_match('/^[0-9a-f]{40}$/', $sha) === 1 && $sha !== str_repeat('0', 40);
    }
}
