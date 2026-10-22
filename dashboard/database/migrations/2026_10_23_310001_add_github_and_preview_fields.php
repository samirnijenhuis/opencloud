<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_apps', function (Blueprint $table) {
            $table->string('slug')->default('');
            $table->string('owner')->default('');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('previews_enabled')->default(true);
            $table->boolean('allow_forks')->default(false);
            $table->string('preview_domain')->nullable();
        });

        Schema::table('environments', function (Blueprint $table) {
            $table->unsignedBigInteger('pr_comment_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('github_apps', function (Blueprint $table) {
            $table->dropColumn(['slug', 'owner']);
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['previews_enabled', 'allow_forks', 'preview_domain']);
        });

        Schema::table('environments', function (Blueprint $table) {
            $table->dropColumn('pr_comment_id');
        });
    }
};
