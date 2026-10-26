<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Every application read the Laravel env names before this column, so each existing row keeps them. */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('env_profile')->default('laravel');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('env_profile');
        });
    }
};
