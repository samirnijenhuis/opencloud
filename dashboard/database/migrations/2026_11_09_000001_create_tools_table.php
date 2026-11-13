<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dev tools of the preview template and of each preview. On the template, the hostname is a pattern with
 * {pr_number}, and each new preview gets a copy with the number filled in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('hostname')->unique();
            $table->timestamps();

            $table->unique(['environment_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tools');
    }
};
