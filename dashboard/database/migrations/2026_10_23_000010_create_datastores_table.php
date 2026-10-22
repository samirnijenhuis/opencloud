<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datastores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cluster_id')->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->string('source');
            $table->string('version');
            $table->string('status');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datastores');
    }
};
