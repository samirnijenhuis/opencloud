<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A pull request from a fork never gets a preview, because the build would run code from outside the team. */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('allow_forks');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('allow_forks')->default(false);
        });
    }
};
