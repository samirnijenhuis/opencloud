<?php

use App\Http\Controllers\Settings\AuditLogController;
use App\Http\Controllers\Settings\InviteController;
use App\Http\Controllers\Settings\MemberController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
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

    Route::get('settings/audit', [AuditLogController::class, 'index'])->name('audit.index');

    Route::post('settings/invites', [InviteController::class, 'store'])->name('invites.store');
    Route::delete('settings/invites/{invite}', [InviteController::class, 'destroy'])->name('invites.destroy');
});
