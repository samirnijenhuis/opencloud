<?php

namespace App\Providers;

use App\Domains\Applications\Models\Application;
use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Backups\Models\Backup;
use App\Domains\Backups\Models\BackupTarget;
use App\Domains\CloudProviders\Models\ProviderCredential;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\Models\Datastore;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Models\CommandRun;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Environments\Models\Process;
use App\Domains\GitHub\Models\GithubApp;
use App\Domains\Members\Models\Invite;
use App\Domains\Members\Models\User;
use App\Domains\Previews\Observers\DeploymentObserver;
use App\Domains\Routing\Models\DashboardDomain;
use App\Domains\Routing\Models\Domain;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DashboardDomain::applyRootUrl();
        Deployment::observe(DeploymentObserver::class);

        Relation::enforceMorphMap([
            'application' => Application::class,
            'audit_event' => AuditEvent::class,
            'backup' => Backup::class,
            'backup_target' => BackupTarget::class,
            'cluster' => Cluster::class,
            'command_run' => CommandRun::class,
            'dashboard_domain' => DashboardDomain::class,
            'datastore' => Datastore::class,
            'datastore_attachment' => DatastoreAttachment::class,
            'deployment' => Deployment::class,
            'domain' => Domain::class,
            'env_var' => EnvVar::class,
            'environment' => Environment::class,
            'github_app' => GithubApp::class,
            'invite' => Invite::class,
            'process' => Process::class,
            'provider_credential' => ProviderCredential::class,
            'user' => User::class,
        ]);
    }
}
