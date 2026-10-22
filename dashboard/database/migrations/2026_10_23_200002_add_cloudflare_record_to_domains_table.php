<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            // Set only when opencloud created the record, so removing the domain never deletes a record it did not create.
            $table->string('cloudflare_zone_id')->nullable();
            $table->string('cloudflare_record_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['cloudflare_zone_id', 'cloudflare_record_id']);
        });
    }
};
