<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The preview template decides the tools of each new preview, and each preview keeps the tools that it got when
 * opencloud created it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->json('preview_tools')->default('[]');
        });

        Schema::table('environments', function (Blueprint $table) {
            $table->json('tools')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('preview_tools');
        });

        Schema::table('environments', function (Blueprint $table) {
            $table->dropColumn('tools');
        });
    }
};
