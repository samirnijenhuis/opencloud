<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each process gets a kind and its own size and scaling. A process ran with the size of web, so each row takes the
 * size and the CPU target of its environment, and a deployed app keeps the same pods. The exclusive scheduler becomes
 * a scheduled process that runs schedule:run each minute, and every other row becomes long-running.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processes', function (Blueprint $table) {
            $table->renameColumn('instances', 'min_instances');
        });

        // SQLite adds a NOT NULL column only with a default, so the new columns start nullable and change after the fill.
        Schema::table('processes', function (Blueprint $table) {
            $table->unsignedInteger('min_instances')->nullable()->change();
            $table->string('kind')->nullable();
            $table->string('schedule', 100)->nullable();
            $table->string('size')->nullable();
            $table->unsignedInteger('max_instances')->nullable();
            $table->unsignedInteger('cpu_target')->nullable();
        });

        DB::table('processes')->update([
            'kind' => 'long_running',
            'size' => DB::raw('(select size from environments where environments.id = processes.environment_id)'),
            'max_instances' => DB::raw('min_instances'),
            'cpu_target' => DB::raw('(select cpu_target from environments where environments.id = processes.environment_id)'),
        ]);

        DB::table('processes')->where('exclusive', true)->where('command', 'like', '%schedule:work%')->update([
            'kind' => 'scheduled',
            'command' => DB::raw("replace(command, 'schedule:work', 'schedule:run')"),
            'schedule' => '* * * * *',
            'min_instances' => null,
            'max_instances' => null,
            'cpu_target' => null,
        ]);

        Schema::table('processes', function (Blueprint $table) {
            $table->string('kind')->nullable(false)->change();
            $table->string('size')->nullable(false)->change();
            $table->dropColumn('exclusive');
        });
    }

    public function down(): void
    {
        Schema::table('processes', function (Blueprint $table) {
            $table->boolean('exclusive')->nullable();
        });

        DB::table('processes')->update(['exclusive' => false]);
        DB::table('processes')->where('kind', 'scheduled')->update([
            'exclusive' => true,
            'command' => DB::raw("replace(command, 'schedule:run', 'schedule:work')"),
            'min_instances' => 1,
        ]);

        Schema::table('processes', function (Blueprint $table) {
            $table->boolean('exclusive')->nullable(false)->change();
            $table->unsignedInteger('min_instances')->nullable(false)->change();
            $table->dropColumn(['kind', 'schedule', 'size', 'max_instances', 'cpu_target']);
        });

        Schema::table('processes', function (Blueprint $table) {
            $table->renameColumn('min_instances', 'instances');
        });
    }
};
