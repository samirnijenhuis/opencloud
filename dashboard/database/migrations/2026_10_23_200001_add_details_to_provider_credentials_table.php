<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_credentials', function (Blueprint $table) {
            $table->json('details')->nullable();
            $table->unique('provider');
        });
    }

    public function down(): void
    {
        Schema::table('provider_credentials', function (Blueprint $table) {
            $table->dropUnique(['provider']);
            $table->dropColumn('details');
        });
    }
};
