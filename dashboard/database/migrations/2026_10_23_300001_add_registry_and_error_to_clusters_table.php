<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clusters', function (Blueprint $table) {
            $table->string('registry_url')->nullable();
            $table->string('registry_username')->nullable();
            $table->text('registry_password')->nullable();
            $table->boolean('registry_insecure')->default(false);
            $table->text('error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clusters', function (Blueprint $table) {
            $table->dropColumn(['registry_url', 'registry_username', 'registry_password', 'registry_insecure', 'error']);
        });
    }
};
