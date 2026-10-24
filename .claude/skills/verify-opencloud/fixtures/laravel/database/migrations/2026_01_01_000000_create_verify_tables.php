<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verify_hits', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at');
        });

        Schema::create('verify_deploys', function (Blueprint $table) {
            $table->id();
            $table->string('cell');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verify_deploys');
        Schema::dropIfExists('verify_hits');
    }
};
