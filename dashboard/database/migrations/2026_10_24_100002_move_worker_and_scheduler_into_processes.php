<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The switches worker_enabled and scheduler_enabled become process rows with the commands that the manifests used.
 * Only a Laravel app ran them, so only a Laravel app gets rows.
 */
return new class extends Migration
{
    private const WORKER = ['name' => 'worker', 'command' => 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600', 'instances' => 1, 'exclusive' => false];

    private const SCHEDULER = ['name' => 'scheduler', 'command' => 'php artisan schedule:work', 'instances' => 1, 'exclusive' => true];

    public function up(): void
    {
        $environments = DB::table('environments')
            ->join('applications', 'applications.id', '=', 'environments.application_id')
            ->where('applications.type', 'laravel')
            ->orderBy('environments.id')
            ->get(['environments.id', 'environments.worker_enabled', 'environments.scheduler_enabled']);
        $now = now();
        $rows = [];

        foreach ($environments as $environment) {
            foreach ([[$environment->worker_enabled, self::WORKER], [$environment->scheduler_enabled, self::SCHEDULER]] as [$enabled, $process]) {
                if ((bool) $enabled) {
                    $rows[] = ['environment_id' => $environment->id, ...$process, 'created_at' => $now, 'updated_at' => $now];
                }
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('processes')->insert($chunk);
        }

        Schema::table('environments', function (Blueprint $table) {
            $table->dropColumn(['worker_enabled', 'scheduler_enabled']);
        });
    }

    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->boolean('worker_enabled')->default(false);
            $table->boolean('scheduler_enabled')->default(false);
        });

        foreach (['worker_enabled' => self::WORKER['name'], 'scheduler_enabled' => self::SCHEDULER['name']] as $column => $name) {
            DB::table('environments')
                ->whereIn('id', DB::table('processes')->where('name', $name)->select('environment_id'))
                ->update([$column => true]);
            DB::table('processes')->where('name', $name)->delete();
        }
    }
};
