<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->string('name', 30);
            $table->string('command', 1000);
            $table->unsignedInteger('instances');
            $table->boolean('exclusive');
            $table->timestamps();

            $table->unique(['environment_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processes');
    }
};
