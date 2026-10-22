<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_targets', function (Blueprint $table) {
            $table->string('region')->default('us-east-1');
            $table->boolean('path_style')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('backup_targets', function (Blueprint $table) {
            $table->dropColumn(['region', 'path_style']);
        });
    }
};
