<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each MySQL or Postgres attachment gets a connection name, and one per environment is the default. An environment
 * had at most one such database, so each one becomes the default "main". The host and the port come from its env
 * values, so the env values stay the same.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('datastore_attachments', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();

            // Two databases of one kind share the server of that kind, so the name tells the attachments apart.
            $table->dropUnique(['datastore_id', 'environment_id']);
            $table->index('datastore_id');
            $table->unique(['environment_id', 'name']);
        });

        DB::statement('CREATE UNIQUE INDEX datastore_attachments_one_default ON datastore_attachments (environment_id) WHERE is_default = 1');

        $databases = DB::table('datastore_attachments')
            ->join('datastores', 'datastores.id', '=', 'datastore_attachments.datastore_id')
            ->whereIn('datastores.kind', ['mysql', 'postgres'])
            ->pluck('datastore_attachments.id');

        foreach ($databases as $id) {
            $env = DB::table('env_vars')
                ->where('datastore_attachment_id', $id)
                ->whereIn('key', ['DB_HOST', 'DB_PORT'])
                ->pluck('value', 'key')
                ->map(fn (?string $value) => $value === null ? null : Crypt::decryptString($value));

            DB::table('datastore_attachments')->where('id', $id)->update([
                'name' => 'main',
                'is_default' => true,
                'host' => $env['DB_HOST'] ?? null,
                'port' => $env['DB_PORT'] ?? null,
            ]);
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX datastore_attachments_one_default');

        Schema::table('datastore_attachments', function (Blueprint $table) {
            $table->dropUnique(['environment_id', 'name']);
            $table->dropIndex(['datastore_id']);
            $table->unique(['datastore_id', 'environment_id']);
        });

        Schema::table('datastore_attachments', function (Blueprint $table) {
            $table->dropColumn(['name', 'is_default', 'host', 'port']);
        });
    }
};
