<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('env_vars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->boolean('is_secret');
            $table->string('available_at');
            $table->string('source');
            $table->timestamps();

            $table->unique(['environment_id', 'key', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('env_vars');
    }
};
