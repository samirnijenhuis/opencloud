<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** A preview deploys the branch of its pull request, so the template never used its branch. Older installs still hold one. */
    public function up(): void
    {
        DB::table('environments')->where('kind', 'preview_template')->update(['branch' => null, 'auto_deploy' => false]);
    }

    /** The old branch is gone, and nothing read it. */
    public function down(): void {}
};
