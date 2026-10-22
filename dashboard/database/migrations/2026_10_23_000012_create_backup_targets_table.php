<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_targets', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint');
            $table->string('bucket');
            $table->text('access_key');
            $table->text('secret_key');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_targets');
    }
};
