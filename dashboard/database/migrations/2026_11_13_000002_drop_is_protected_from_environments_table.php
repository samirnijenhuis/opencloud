<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Every environment has the same rules, so no environment is protected. */
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->dropColumn('is_protected');
        });
    }

    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->boolean('is_protected')->default(false);
        });
    }
};
