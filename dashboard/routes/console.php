<?php

use App\Clusters\ClusterStatus;
use App\Enums\DatastoreKind;
use App\Enums\DatastoreSource;
use App\Jobs\BackupDashboard;
use App\Jobs\BackupDatastore;
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
// A cluster that OpenTofu creates or destroys has no Caddy to reach.
Schedule::call(fn () => Cluster::query()->whereNotIn('status', [ClusterStatus::Provisioning, ClusterStatus::Destroying])->each(fn (Cluster $cluster) => SyncCaddy::dispatch($cluster)))
    ->name('sync-caddy')
    ->everyFiveMinutes();

Schedule::job(new FailStuckDeployments)->everyMinute();

Schedule::call(function () {
    Datastore::query()->where('source', DatastoreSource::Standard)->whereIn('kind', [DatastoreKind::Mysql, DatastoreKind::Postgres])->each(fn (Datastore $datastore) => BackupDatastore::dispatch($datastore));
    Datastore::query()->where('kind', DatastoreKind::Rustfs)->each(fn (Datastore $datastore) => CopyBuckets::dispatch($datastore));
    BackupDashboard::dispatch();
})->name('nightly-backups')->dailyAt('03:00');
