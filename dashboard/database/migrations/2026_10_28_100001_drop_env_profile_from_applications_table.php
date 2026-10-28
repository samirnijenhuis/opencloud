<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The preset decides the env names now. The next deploy of each environment writes the env values of the new names. */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('env_profile');
        });
    }

    /** Each application gets the profile that its preset gave a new application before this migration. */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('env_profile')->default('laravel');
        });

        DB::table('applications')->where('type', '!=', 'laravel')->update(['env_profile' => 'generic']);
    }
};
