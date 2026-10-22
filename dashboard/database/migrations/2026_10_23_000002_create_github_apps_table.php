<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_apps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id');
            $table->text('private_key');
            $table->text('webhook_secret');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_apps');
    }
};
