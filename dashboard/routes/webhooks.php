<?php

use App\Domains\Routing\CaddyConfig;
use App\Http\Controllers\DeployHookController;
use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\WakeController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/github', GitHubWebhookController::class)->name('webhooks.github');

// The token in the URL is the proof. The limit makes guessing a token slow.
Route::post('hooks/deploy/{token}', [DeployHookController::class, 'trigger'])->middleware('throttle:10,1,deploy-hook')->name('hooks.deploy');

// Caddy sends each request whose app does not answer here, with any method. A visitor has no session or CSRF token.
Route::any(CaddyConfig::WAKE_PATH.'{domain:hostname}', WakeController::class)->middleware('throttle:120,1,wake')->name('wake');
