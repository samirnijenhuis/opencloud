<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('datastores', function (Blueprint $table) {
            $table->text('root_password')->nullable();
            $table->text('error')->nullable();
        });

        DB::statement("CREATE UNIQUE INDEX datastores_one_standard_per_cluster_and_kind ON datastores (cluster_id, kind) WHERE source = 'standard'");

        Schema::table('datastore_attachments', function (Blueprint $table) {
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
        });

        Schema::table('env_vars', function (Blueprint $table) {
            $table->foreignId('datastore_attachment_id')->nullable()->constrained()->cascadeOnDelete();
        });

        Schema::table('backups', function (Blueprint $table) {
            $table->foreignId('datastore_attachment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('database_name')->nullable();
            $table->string('disk')->default('local');
            $table->text('error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('datastore_attachment_id');
            $table->dropColumn(['database_name', 'disk', 'error']);
        });

        Schema::table('env_vars', function (Blueprint $table) {
            $table->dropConstrainedForeignId('datastore_attachment_id');
        });

        Schema::table('datastore_attachments', function (Blueprint $table) {
            $table->dropColumn(['status', 'error']);
        });

        DB::statement('DROP INDEX datastores_one_standard_per_cluster_and_kind');

        Schema::table('datastores', function (Blueprint $table) {
            $table->dropColumn(['root_password', 'error']);
        });
    }
};
