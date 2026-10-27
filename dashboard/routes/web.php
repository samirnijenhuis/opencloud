<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\ApplicationEnvProfileController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\ClusterController;
use App\Http\Controllers\CommandRunController;
use App\Http\Controllers\CreatedClusterController;
use App\Http\Controllers\DatastoreController;
use App\Http\Controllers\DeployHookController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\EnvironmentColorController;
use App\Http\Controllers\EnvironmentController;
use App\Http\Controllers\EnvironmentLogController;
use App\Http\Controllers\EnvVarController;
use App\Http\Controllers\InviteAcceptController;
use App\Http\Controllers\LiveStatusController;
use App\Http\Controllers\PreviewSettingsController;
use App\Http\Controllers\ProcessController;
use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('throttle:10,1')->group(function () {
    Route::get('setup/{token}', [SetupController::class, 'show'])->name('setup');
    Route::post('setup/{token}', [SetupController::class, 'store'])->name('setup.store');

    Route::middleware('guest')->group(function () {
        Route::get('invites/{token}', [InviteAcceptController::class, 'show'])->name('invites.show');
        Route::post('invites/{token}', [InviteAcceptController::class, 'store'])->name('invites.accept');
    });
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('dashboard', '/applications')->name('dashboard');

    Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
    Route::get('applications/create', [ApplicationController::class, 'create'])->name('applications.create');
    Route::post('applications', [ApplicationController::class, 'store'])->name('applications.store');
    Route::get('applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');
    Route::patch('applications/{application}/env-profile', [ApplicationEnvProfileController::class, 'update'])->name('applications.env-profile.update');
    Route::patch('applications/{application}/previews', [PreviewSettingsController::class, 'update'])->name('applications.previews.update');

    Route::get('environments/{environment}', [EnvironmentController::class, 'show'])->name('environments.show');
    Route::get('environments/{environment}/deployments', [DeploymentController::class, 'index'])->name('environments.deployments');
    Route::post('environments/{environment}/deployments', [DeploymentController::class, 'store'])->name('deployments.store');
    Route::get('deployments/{deployment}', [DeploymentController::class, 'show'])->name('deployments.show');

    Route::get('environments/{environment}/logs', [EnvironmentLogController::class, 'show'])->name('environments.logs');
    Route::get('environments/{environment}/logs/read', [EnvironmentLogController::class, 'read'])->name('environments.logs.read');

    Route::get('environments/{environment}/status', [LiveStatusController::class, 'environment'])->name('environments.status');

    Route::get('environments/{environment}/commands', [EnvironmentController::class, 'commands'])->name('environments.commands');
    Route::get('environments/{environment}/command-runs', [CommandRunController::class, 'index'])->name('environments.command-runs');
    Route::post('environments/{environment}/command-runs', [CommandRunController::class, 'store'])->name('command-runs.store');
    Route::get('command-runs/{commandRun}', [CommandRunController::class, 'show'])->name('command-runs.show');

    Route::get('environments/{environment}/env', [EnvVarController::class, 'index'])->name('environments.env');
    Route::post('environments/{environment}/env', [EnvVarController::class, 'store'])->name('env-vars.store');
    Route::delete('env-vars/{envVar}', [EnvVarController::class, 'destroy'])->name('env-vars.destroy');

    Route::get('environments/{environment}/domains', [DomainController::class, 'index'])->name('environments.domains');
    Route::post('environments/{environment}/domains', [DomainController::class, 'store'])->name('domains.store');
    Route::post('domains/{domain}/check', [DomainController::class, 'check'])->name('domains.check');
    Route::delete('domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');

    Route::get('environments/{environment}/settings', [EnvironmentController::class, 'settings'])->name('environments.settings');
    Route::patch('environments/{environment}/settings', [EnvironmentController::class, 'update'])->name('environments.update');
    Route::post('environments/{environment}/processes', [ProcessController::class, 'store'])->name('processes.store');
    Route::patch('processes/{process}', [ProcessController::class, 'update'])->name('processes.update');
    Route::delete('processes/{process}', [ProcessController::class, 'destroy'])->name('processes.destroy');

    Route::patch('environments/{environment}/color', [EnvironmentColorController::class, 'update'])->name('environments.color.update');

    Route::get('environments/{environment}/deploy-hook', [DeployHookController::class, 'show'])->name('environments.deploy-hook');
    Route::post('environments/{environment}/deploy-hook', [DeployHookController::class, 'store'])->name('environments.deploy-hook.enable');
    Route::post('environments/{environment}/deploy-hook/regenerate', [DeployHookController::class, 'regenerate'])->name('environments.deploy-hook.regenerate');
    Route::delete('environments/{environment}/deploy-hook', [DeployHookController::class, 'destroy'])->name('environments.deploy-hook.disable');

    Route::get('environments/{environment}/datastores', [DatastoreController::class, 'index'])->name('environments.datastores');
    Route::post('environments/{environment}/datastores', [DatastoreController::class, 'store'])->name('datastores.store');
    Route::delete('datastore-attachments/{attachment}', [DatastoreController::class, 'destroy'])->name('datastores.destroy');
    Route::post('datastore-attachments/{attachment}/default', [DatastoreController::class, 'makeDefault'])->name('datastores.default');
    Route::post('datastore-attachments/{attachment}/backups', [BackupController::class, 'store'])->name('backups.store');
    Route::post('backups/{backup}/restore', [BackupController::class, 'restore'])->name('backups.restore');

    Route::get('clusters', [ClusterController::class, 'index'])->name('clusters.index');
    Route::get('clusters/create', [ClusterController::class, 'create'])->name('clusters.create');
    Route::get('clusters/create/{provider}', [CreatedClusterController::class, 'create'])->name('clusters.provider.create');
    Route::post('clusters/create/{provider}', [CreatedClusterController::class, 'store'])->name('clusters.provider.store');
    Route::delete('clusters/{cluster}/destroy', [CreatedClusterController::class, 'destroy'])->name('clusters.provider.destroy');
    Route::post('clusters', [ClusterController::class, 'store'])->name('clusters.store');
    Route::get('clusters/{cluster}', [ClusterController::class, 'show'])->name('clusters.show');
    Route::post('clusters/{cluster}/reinstall', [ClusterController::class, 'reinstall'])->name('clusters.reinstall');
    Route::get('clusters/{cluster}/capacity', [LiveStatusController::class, 'cluster'])->name('clusters.capacity');
    Route::delete('clusters/{cluster}', [ClusterController::class, 'destroy'])->name('clusters.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
