<?php

use App\Http\Controllers\VerifyController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/verify', VerifyController::class);
