<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clusters', function (Blueprint $table) {
            $table->string('region')->nullable();
            $table->string('node_size')->nullable();
            $table->unsignedSmallInteger('min_nodes')->nullable();
            $table->unsignedSmallInteger('max_nodes')->nullable();
            $table->string('provider_cluster_id')->nullable();
            $table->string('provider_vpc_id')->nullable();
            $table->longText('tofu_log')->nullable();
        });

        Schema::table('datastores', function (Blueprint $table) {
            $table->longText('tofu_log')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('datastores', function (Blueprint $table) {
            $table->dropColumn('tofu_log');
        });

        Schema::table('clusters', function (Blueprint $table) {
            $table->dropColumn(['region', 'node_size', 'min_nodes', 'max_nodes', 'provider_cluster_id', 'provider_vpc_id', 'tofu_log']);
        });
    }
};
