<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Asks GitHub once a day for the latest release of opencloud. The sidebar tells the owner when that release is newer than the running one.
 * A failed check changes nothing. The next day tries again.
 */
class CheckLatestRelease implements ShouldQueue
{
    use Queueable;

    public const CACHE_KEY = 'opencloud.latest_release';

    public function handle(): void
    {
        try {
            $tag = Http::timeout(10)->acceptJson()
                ->get('https://api.github.com/repos/'.config('opencloud.repository').'/releases/latest')
                ->throw()
                ->json('tag_name');
        } catch (Throwable) {
            return;
        }

        if (is_string($tag) && self::isRelease($tag)) {
            Cache::put(self::CACHE_KEY, $tag, now()->addDays(3));
        }
    }

    /** The release that the owner can upgrade to, or null. */
    public static function newerRelease(): ?string
    {
        $latest = Cache::get(self::CACHE_KEY);

        return is_string($latest) && self::isNewer($latest, (string) config('opencloud.version')) ? $latest : null;
    }

    /** A pre-release such as v1.2.0-rc.1 is older than v1.2.0. A running version that is not a release, such as "dev", is never older. */
    public static function isNewer(string $candidate, string $running): bool
    {
        return self::isRelease($candidate) && self::isRelease($running) && version_compare(ltrim($candidate, 'v'), ltrim($running, 'v'), '>');
    }

    private static function isRelease(string $version): bool
    {
        return preg_match('/^v?\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version) === 1;
    }
}
