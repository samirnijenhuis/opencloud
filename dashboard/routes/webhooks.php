<?php

use App\Http\Controllers\DeployHookController;
use App\Http\Controllers\GitHubWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/github', GitHubWebhookController::class)->name('webhooks.github');

// The token in the URL is the proof. The limit makes guessing a token slow.
Route::post('hooks/deploy/{token}', [DeployHookController::class, 'trigger'])->middleware('throttle:10,1,deploy-hook')->name('hooks.deploy');
