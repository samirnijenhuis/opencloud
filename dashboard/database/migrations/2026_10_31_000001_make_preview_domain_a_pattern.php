<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PREFIX = 'pr-{pr_number}.';

    /** A running preview keeps its address, because the old base domain gets the old first part. */
    public function up(): void
    {
        DB::update('UPDATE applications SET preview_domain = ? || preview_domain WHERE preview_domain IS NOT NULL', [self::PREFIX]);
    }

    /** A pattern that does not start with the old first part cannot become a base domain, so it becomes null. */
    public function down(): void
    {
        DB::update(
            'UPDATE applications SET preview_domain = CASE WHEN substr(preview_domain, 1, ?) = ? THEN substr(preview_domain, ?) END WHERE preview_domain IS NOT NULL',
            [strlen(self::PREFIX), self::PREFIX, strlen(self::PREFIX) + 1],
        );
    }
};
