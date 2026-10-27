<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A bucket gets a name and a default, like a database. A name is unique among the attachments of one family in an
 * environment, and each family has its own default, so each attachment stores its family for the two unique indexes.
 * An environment had at most one bucket, so each one becomes the default "main". Its names on the server stay, so its
 * env values stay the same. A bucket whose datastore is being destroyed keeps no name, like a managed database.
 */
return new class extends Migration
{
    private const FAMILIES = ['mysql' => 'database', 'postgres' => 'database', 'valkey' => 'key_value', 'rustfs' => 'bucket'];

    public function up(): void
    {
        Schema::table('datastore_attachments', function (Blueprint $table) {
            $table->string('family')->nullable();
        });

        foreach (self::FAMILIES as $kind => $family) {
            DB::table('datastore_attachments')
                ->whereIn('datastore_id', DB::table('datastores')->where('kind', $kind)->select('id'))
                ->update(['family' => $family]);
        }

        // The bucket "main" can have the name of the database "main", so the old index goes before the names.
        DB::statement('DROP INDEX datastore_attachments_one_default');

        Schema::table('datastore_attachments', function (Blueprint $table) {
            $table->dropUnique(['environment_id', 'name']);
        });

        DB::table('datastore_attachments')
            ->where('family', 'bucket')
            ->whereNull('name')
            ->whereNotIn('datastore_id', DB::table('datastores')->where('status', 'destroying')->select('id'))
            ->update(['name' => 'main', 'is_default' => true]);

        Schema::table('datastore_attachments', function (Blueprint $table) {
            $table->unique(['environment_id', 'family', 'name']);
        });

        DB::statement('CREATE UNIQUE INDEX datastore_attachments_one_default ON datastore_attachments (environment_id, family) WHERE is_default = 1');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX datastore_attachments_one_default');

        Schema::table('datastore_attachments', function (Blueprint $table) {
            $table->dropUnique(['environment_id', 'family', 'name']);
        });

        DB::table('datastore_attachments')->where('family', 'bucket')->update(['name' => null, 'is_default' => false]);

        Schema::table('datastore_attachments', function (Blueprint $table) {
            $table->unique(['environment_id', 'name']);
            $table->dropColumn('family');
        });

        DB::statement('CREATE UNIQUE INDEX datastore_attachments_one_default ON datastore_attachments (environment_id) WHERE is_default = 1');
    }
};
