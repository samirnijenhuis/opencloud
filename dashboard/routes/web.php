<?php

use App\Http\Controllers\InviteAcceptController;
use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware('throttle:10,1')->group(function () {
    Route::get('setup/{token}', [SetupController::class, 'show'])->name('setup');
    Route::post('setup/{token}', [SetupController::class, 'store'])->name('setup.store');

    Route::middleware('guest')->group(function () {
        Route::get('invites/{token}', [InviteAcceptController::class, 'show'])->name('invites.show');
        Route::post('invites/{token}', [InviteAcceptController::class, 'store'])->name('invites.accept');
    });
});

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
