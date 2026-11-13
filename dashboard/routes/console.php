<?php

use App\Domains\Backups\Jobs\BackupDashboard;
use App\Domains\Backups\Jobs\BackupDatastore;
use App\Domains\Backups\Jobs\CopyBuckets;
use App\Domains\Clusters\Enums\ClusterStatus;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Capabilities;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Provisioners;
use App\Domains\Deployments\Jobs\FailStuckDeployments;
use App\Domains\Environments\Jobs\SleepIdleEnvironments;
use App\Domains\Routing\Jobs\CheckCertificates;
use App\Domains\Routing\Jobs\SyncCaddy;
use App\Domains\Upgrades\Jobs\CheckLatestRelease;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The full config every five minutes repairs any drift, for example after a Caddy restart without its saved config.
Schedule::call(fn () => Cluster::query()->where('status', ClusterStatus::READY)->each(fn (Cluster $cluster) => SyncCaddy::dispatch($cluster)))
    ->name('sync-caddy')
    ->everyFiveMinutes();

Schedule::job(new FailStuckDeployments)->everyMinute();

Schedule::job(new CheckCertificates)->everyMinute();

Schedule::job(new SleepIdleEnvironments)->everyMinute();

Schedule::job(new CheckLatestRelease)->daily();

Schedule::call(function () {
    Provisioners::datastores(fn (Capabilities $can) => $can->dumps !== null)->each(fn (Datastore $datastore) => BackupDatastore::dispatch($datastore));
    Provisioners::datastores(fn (Capabilities $can) => $can->copiesBuckets)->each(fn (Datastore $datastore) => CopyBuckets::dispatch($datastore));
    BackupDashboard::dispatch();
})->name('nightly-backups')->dailyAt('03:00');
