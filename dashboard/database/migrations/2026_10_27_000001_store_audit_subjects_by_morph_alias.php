<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An audit event named its subject by the model class. The morph map names it by an alias, so a model class can move.
 */
return new class extends Migration
{
    private const ALIASES = [
        'App\\Models\\Application' => 'application',
        'App\\Models\\AuditEvent' => 'audit_event',
        'App\\Models\\Backup' => 'backup',
        'App\\Models\\BackupTarget' => 'backup_target',
        'App\\Models\\Cluster' => 'cluster',
        'App\\Models\\CommandRun' => 'command_run',
        'App\\Models\\DashboardDomain' => 'dashboard_domain',
        'App\\Models\\Datastore' => 'datastore',
        'App\\Models\\DatastoreAttachment' => 'datastore_attachment',
        'App\\Models\\Deployment' => 'deployment',
        'App\\Models\\Domain' => 'domain',
        'App\\Models\\EnvVar' => 'env_var',
        'App\\Models\\Environment' => 'environment',
        'App\\Models\\GithubApp' => 'github_app',
        'App\\Models\\Invite' => 'invite',
        'App\\Models\\Process' => 'process',
        'App\\Models\\ProviderCredential' => 'provider_credential',
        'App\\Models\\User' => 'user',
    ];

    public function up(): void
    {
        foreach (self::ALIASES as $class => $alias) {
            DB::table('audit_events')->where('subject_type', $class)->update(['subject_type' => $alias]);
        }
    }

    public function down(): void
    {
        foreach (self::ALIASES as $class => $alias) {
            DB::table('audit_events')->where('subject_type', $alias)->update(['subject_type' => $class]);
        }
    }
};
