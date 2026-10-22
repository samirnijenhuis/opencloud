<?php

use App\Jobs\FailStuckDeployments;
use App\Jobs\SyncCaddy;
use App\Models\Cluster;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The full config every five minutes repairs any drift, for example after a Caddy restart without its saved config.
Schedule::call(fn () => Cluster::query()->each(fn (Cluster $cluster) => SyncCaddy::dispatch($cluster)))
    ->name('sync-caddy')
    ->everyFiveMinutes();

Schedule::job(new FailStuckDeployments)->everyMinute();
