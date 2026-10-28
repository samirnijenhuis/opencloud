<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Laravel scheduler runs schedule:work in one long-running process, as Laravel Cloud runs it. A CronJob stopped
 * each run pod after schedule:run, so a background task died with the pod and the file cache lost its locks. Only the
 * rows that the old preset made change. Every other scheduled row stays a CronJob.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('processes')
            ->where('kind', 'scheduled')
            ->where('command', 'php artisan schedule:run')
            ->where('schedule', '* * * * *')
            ->update([
                'kind' => 'long_running',
                'command' => 'php artisan schedule:work',
                'schedule' => null,
                'min_instances' => 1,
                'max_instances' => 1,
                'cpu_target' => DB::raw('(select cpu_target from environments where environments.id = processes.environment_id)'),
            ]);
    }

    public function down(): void
    {
        DB::table('processes')
            ->where('kind', 'long_running')
            ->where('command', 'php artisan schedule:work')
            ->where('min_instances', 1)
            ->where('max_instances', 1)
            ->update([
                'kind' => 'scheduled',
                'command' => 'php artisan schedule:run',
                'schedule' => '* * * * *',
                'min_instances' => null,
                'max_instances' => null,
                'cpu_target' => null,
            ]);
    }
};
