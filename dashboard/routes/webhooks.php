<?php

use App\Http\Controllers\GitHubWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/github', GitHubWebhookController::class)->name('webhooks.github');
