<?php

namespace App\GitHub;

/**
 * The GitHub App manifest that the owner posts to github.com. GitHub creates the app from it.
 */
final class Manifest
{
    /** GitHub refuses an app name longer than 34 characters. */
    private const MAX_NAME_LENGTH = 34;

    /** @return array<string, mixed> */
    public static function for(string $appUrl): array
    {
        $base = rtrim($appUrl, '/');
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        $name = rtrim(substr('opencloud-'.preg_replace('/[^a-z0-9]+/', '-', $host), 0, self::MAX_NAME_LENGTH), '-');

        return [
            'name' => $name,
            'url' => $base,
            'hook_attributes' => ['url' => "{$base}/webhooks/github", 'active' => true],
            'redirect_url' => "{$base}/settings/github/callback",
            'public' => false,
            'default_permissions' => [
                'contents' => 'read',
                'metadata' => 'read',
                'pull_requests' => 'write',
                'issues' => 'write',
            ],
            'default_events' => ['push', 'pull_request'],
        ];
    }
}
