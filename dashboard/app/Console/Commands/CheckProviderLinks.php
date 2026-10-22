<?php

namespace App\Console\Commands;

use App\CloudProviders\Providers;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class CheckProviderLinks extends Command
{
    protected $signature = 'opencloud:check-provider-links';

    protected $description = 'Request the key page of each provider and fail when one does not answer with 2xx or 3xx';

    public function handle(): int
    {
        $failed = 0;

        foreach (Providers::all() as $guide) {
            $url = $guide->linkToCheck();

            if ($url === null) {
                continue;
            }

            try {
                $status = Http::withoutRedirecting()->timeout(20)->withUserAgent('opencloud-link-check')->get($url)->status();
            } catch (ConnectionException $e) {
                $status = 0;
            }

            $ok = $status >= 200 && $status < 400;
            $failed += $ok ? 0 : 1;
            $this->line(($ok ? 'OK   ' : 'FAIL ')."{$guide->label} {$status} {$url}");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
