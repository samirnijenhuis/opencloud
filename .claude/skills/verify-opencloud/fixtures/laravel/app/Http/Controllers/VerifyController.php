<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Reports what the opencloud verification matrix checks: env values, the build, the database, and the cache. */
class VerifyController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'fixture' => 'laravel',
            'marker' => config('verify.marker'),
            'build' => trim((string) @file_get_contents(storage_path('app/build-marker'))) ?: null,
            'secret_sha' => config('verify.secret_sha'),
            'db' => config('verify.database') ? $this->database() : null,
            'cache' => $this->cache(),
        ]);
    }

    /** @return array<string, mixed> */
    private function database(): array
    {
        try {
            DB::table('verify_hits')->insert(['created_at' => now()]);

            return [
                'driver' => DB::connection()->getDriverName(),
                'ok' => true,
                'hits' => DB::table('verify_hits')->count(),
                'deploys' => DB::table('verify_deploys')->count(),
            ];
        } catch (Throwable $e) {
            return ['driver' => config('database.default'), 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function cache(): array
    {
        $key = 'verify:'.Str::random(12);

        try {
            Cache::put($key, 'ok', 60);

            return ['driver' => config('cache.default'), 'ok' => Cache::get($key) === 'ok', 'hits' => Cache::increment('verify:hits')];
        } catch (Throwable $e) {
            return ['driver' => config('cache.default'), 'ok' => false, 'error' => $e->getMessage()];
        }
    }
}
