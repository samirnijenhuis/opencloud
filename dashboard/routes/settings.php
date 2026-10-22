<?php

use App\Http\Controllers\Settings\AuditLogController;
use App\Http\Controllers\Settings\BackupTargetController;
use App\Http\Controllers\Settings\DashboardDomainController;
use App\Http\Controllers\Settings\GitHubController;
use App\Http\Controllers\Settings\InviteController;
use App\Http\Controllers\Settings\MemberController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\ProviderKeyController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');

    Route::get('settings/members', [MemberController::class, 'index'])->name('members.index');
    Route::patch('settings/members/{user}', [MemberController::class, 'update'])->name('members.update');
    Route::delete('settings/members/{user}', [MemberController::class, 'destroy'])->name('members.destroy');

    Route::get('settings/providers', [ProviderKeyController::class, 'index'])->name('provider-keys.index');
    Route::put('settings/providers/{provider}', [ProviderKeyController::class, 'update'])->name('provider-keys.update');
    Route::post('settings/provider-credentials/{credential}/check', [ProviderKeyController::class, 'recheck'])->name('provider-keys.check');
    Route::delete('settings/provider-credentials/{credential}', [ProviderKeyController::class, 'destroy'])->name('provider-keys.destroy');

    Route::get('settings/audit', [AuditLogController::class, 'index'])->name('audit.index');

    Route::get('settings/backups', [BackupTargetController::class, 'edit'])->name('backup-target.edit');
    Route::put('settings/backups', [BackupTargetController::class, 'update'])->name('backup-target.update');
    Route::delete('settings/backups', [BackupTargetController::class, 'destroy'])->name('backup-target.destroy');

    Route::get('settings/dashboard-address', [DashboardDomainController::class, 'edit'])->name('dashboard-domain.edit');
    Route::put('settings/dashboard-address', [DashboardDomainController::class, 'update'])->name('dashboard-domain.update');
    Route::post('settings/dashboard-address/check', [DashboardDomainController::class, 'check'])->name('dashboard-domain.check');
    Route::delete('settings/dashboard-address', [DashboardDomainController::class, 'destroy'])->name('dashboard-domain.destroy');
    Route::get('settings/github', [GitHubController::class, 'index'])->name('github.index');
    Route::get('settings/github/callback', [GitHubController::class, 'callback'])->name('github.callback');

    Route::post('settings/invites', [InviteController::class, 'store'])->name('invites.store');
    Route::delete('settings/invites/{invite}', [InviteController::class, 'destroy'])->name('invites.destroy');
});
