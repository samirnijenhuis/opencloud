<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 0 means no limit. */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_previews')->default(5);
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('max_previews');
        });
    }
};
