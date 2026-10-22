<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datastore_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('datastore_id')->constrained()->restrictOnDelete();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->string('database_name')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->timestamps();

            $table->unique(['datastore_id', 'environment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datastore_attachments');
    }
};
