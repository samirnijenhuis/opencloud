<?php

use App\Clusters\ClusterStatus;
use App\Datastores\Capabilities;
use App\Datastores\Provisioners;
use App\Jobs\BackupDashboard;
use App\Jobs\BackupDatastore;
use App\Jobs\CheckCertificates;
use App\Jobs\CheckLatestRelease;
use App\Jobs\CopyBuckets;
use App\Jobs\FailStuckDeployments;
use App\Jobs\SyncCaddy;
use App\Models\Cluster;
use App\Models\Datastore;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The full config every five minutes repairs any drift, for example after a Caddy restart without its saved config.
Schedule::call(fn () => Cluster::query()->where('status', ClusterStatus::Ready)->each(fn (Cluster $cluster) => SyncCaddy::dispatch($cluster)))
    ->name('sync-caddy')
    ->everyFiveMinutes();

Schedule::job(new FailStuckDeployments)->everyMinute();

Schedule::job(new CheckCertificates)->everyMinute();

Schedule::job(new CheckLatestRelease)->daily();

Schedule::call(function () {
    Provisioners::datastores(fn (Capabilities $can) => $can->dumps !== null)->each(fn (Datastore $datastore) => BackupDatastore::dispatch($datastore));
    Provisioners::datastores(fn (Capabilities $can) => $can->copiesBuckets)->each(fn (Datastore $datastore) => CopyBuckets::dispatch($datastore));
    BackupDashboard::dispatch();
})->name('nightly-backups')->dailyAt('03:00');
