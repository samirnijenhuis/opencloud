<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Opencloud runs this as a deploy command before the new version gets traffic.
Artisan::command('verify:mark-deploy {cell}', function (string $cell) {
    $this->line("deploy-marker-{$cell}");

    if (config('verify.database')) {
        DB::table('verify_deploys')->insert(['cell' => $cell, 'created_at' => now()]);
        $this->line('The deploy command wrote one row to verify_deploys.');
    }
})->purpose('Record a deploy for the opencloud verification matrix');
