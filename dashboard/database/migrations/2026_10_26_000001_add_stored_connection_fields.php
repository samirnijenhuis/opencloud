<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A managed or external key-value store or bucket keeps its whole address on the attachment, like a database does.
 * An external datastore runs outside every cluster, so its datastore row has no cluster.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('datastore_attachments', function (Blueprint $table) {
            $table->boolean('tls')->default(false);
            $table->string('region')->nullable();
            $table->boolean('path_style')->default(false);
        });

        $this->changeClusterId(nullable: true);
    }

    public function down(): void
    {
        $this->changeClusterId(nullable: false);

        Schema::table('datastore_attachments', function (Blueprint $table) {
            $table->dropColumn(['tls', 'region', 'path_style']);
        });
    }

    /** SQLite rebuilds the table for the change and drops the WHERE of a partial index, so the index goes first. */
    private function changeClusterId(bool $nullable): void
    {
        DB::statement('DROP INDEX datastores_one_standard_per_cluster_and_kind');

        Schema::table('datastores', function (Blueprint $table) use ($nullable) {
            $table->foreignId('cluster_id')->nullable($nullable)->change();
        });

        DB::statement("CREATE UNIQUE INDEX datastores_one_standard_per_cluster_and_kind ON datastores (cluster_id, kind) WHERE source = 'standard'");
    }
};
