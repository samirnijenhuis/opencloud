<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->unsignedInteger('sleep_after_minutes')->nullable();
            $table->timestamp('last_request_at')->nullable();
            $table->timestamp('slept_at')->nullable();
            $table->timestamp('woke_at')->nullable();
            $table->index(['sleep_after_minutes', 'slept_at']);
        });

        // A preview sleeps by default. A permanent environment sleeps only when the user turns sleep on.
        DB::table('environments')->whereIn('kind', ['preview', 'preview_template'])->update(['sleep_after_minutes' => 15]);
    }

    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->dropIndex(['sleep_after_minutes', 'slept_at']);
            $table->dropColumn(['sleep_after_minutes', 'last_request_at', 'slept_at', 'woke_at']);
        });
    }
};
