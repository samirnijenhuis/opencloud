<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind');
            $table->boolean('is_protected');
            $table->string('branch')->nullable();
            $table->boolean('auto_deploy');
            $table->unsignedInteger('pr_number')->nullable();
            $table->string('size');
            $table->unsignedInteger('min_instances');
            $table->unsignedInteger('max_instances');
            $table->unsignedInteger('cpu_target');
            $table->string('build_command')->nullable();
            $table->json('deploy_commands');
            $table->string('health_path');
            $table->boolean('worker_enabled');
            $table->boolean('scheduler_enabled');
            $table->timestamps();

            $table->unique(['application_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environments');
    }
};
