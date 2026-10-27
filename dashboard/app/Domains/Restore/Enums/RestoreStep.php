<?php

namespace App\Domains\Restore\Enums;

/** The steps of a full restore on the local cluster. The order of the cases is the order they run in. */
enum RestoreStep: string
{
    case RETIRE_DEPLOYMENTS = 'retire_deployments';
    case PROVISION_DATASTORES = 'provision_datastores';
    case RESTORE_DATABASES = 'restore_databases';
    case RESTORE_BUCKETS = 'restore_buckets';
    case DEPLOY = 'deploy';
    case CHECK_DOMAINS = 'check_domains';
    case SYNC_CADDY = 'sync_caddy';

    public function label(): string
    {
        return match ($this) {
            self::RETIRE_DEPLOYMENTS => 'Retire the old deployments',
            self::PROVISION_DATASTORES => 'Provision the datastores',
            self::RESTORE_DATABASES => 'Restore the databases',
            self::RESTORE_BUCKETS => 'Copy the buckets back',
            self::DEPLOY => 'Deploy the environments',
            self::CHECK_DOMAINS => 'Check the domains',
            self::SYNC_CADDY => 'Sync Caddy',
        };
    }
}
