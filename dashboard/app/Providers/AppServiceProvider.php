<?php

namespace App\Providers;

use App\Models\Deployment;
use App\Observers\DeploymentObserver;
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
        Deployment::observe(DeploymentObserver::class);
    }
}
