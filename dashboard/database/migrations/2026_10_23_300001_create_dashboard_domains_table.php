<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_domains', function (Blueprint $table) {
            $table->id();
            $table->string('hostname')->unique();
            $table->string('dns_status')->default('pending');
            $table->json('dns_found')->nullable();
            $table->timestamp('dns_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_domains');
    }
};
