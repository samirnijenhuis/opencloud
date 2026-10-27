<?php

namespace App\Providers;

use App\Domains\Deployments\Models\Deployment;
use App\Domains\Previews\Observers\DeploymentObserver;
use App\Domains\Routing\Models\DashboardDomain;
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
    }
}
