<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->string('commit_sha', 40);
            $table->string('image')->nullable();
            $table->string('status');
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        DB::statement("CREATE UNIQUE INDEX deployments_one_live_per_environment ON deployments (environment_id) WHERE status = 'live'");
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
