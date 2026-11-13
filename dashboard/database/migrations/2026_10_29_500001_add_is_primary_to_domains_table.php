<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false);
        });

        DB::statement('CREATE UNIQUE INDEX domains_one_primary ON domains (environment_id) WHERE is_primary = 1');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX domains_one_primary');

        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('is_primary');
        });
    }
};
