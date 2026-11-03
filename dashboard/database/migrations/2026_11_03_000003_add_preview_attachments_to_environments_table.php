<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The preview template holds the list of resources that each new preview gets. Before, a preview got the resources of
 * production. So each template starts with the resources that production has now, and its previews keep the same
 * resources. A database that DigitalOcean destroys has no name, and a preview never got it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->json('preview_attachments')->default('[]');
        });

        DB::table('environments')->where('kind', 'preview_template')->orderBy('id')->each(function (object $template) {
            $production = DB::table('environments')->where('application_id', $template->application_id)->where('name', 'production')->select('id');

            $entries = DB::table('datastore_attachments')
                ->join('datastores', 'datastores.id', '=', 'datastore_attachments.datastore_id')
                ->whereIn('datastore_attachments.environment_id', $production)
                ->orderBy('datastore_attachments.id')
                ->get(['datastores.kind', 'datastore_attachments.family', 'datastore_attachments.name', 'datastore_attachments.is_default'])
                ->reject(fn (object $row) => $row->family !== 'key_value' && $row->name === null)
                ->map(fn (object $row) => ['kind' => $row->kind, 'name' => $row->name, 'is_default' => (bool) $row->is_default])
                ->values();

            DB::table('environments')->where('id', $template->id)->update(['preview_attachments' => $entries->toJson()]);
        });
    }

    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->dropColumn('preview_attachments');
        });
    }
};
