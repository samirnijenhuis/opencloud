<?php

namespace App\Domains\Restore\Enums;

/** The steps of a full restore on the local cluster. The order of the cases is the order they run in. */
enum RestoreStep: string
{
    case RetireDeployments = 'retire_deployments';
    case ProvisionDatastores = 'provision_datastores';
    case RestoreDatabases = 'restore_databases';
    case RestoreBuckets = 'restore_buckets';
    case Deploy = 'deploy';
    case CheckDomains = 'check_domains';
    case SyncCaddy = 'sync_caddy';

    public function label(): string
    {
        return match ($this) {
            self::RetireDeployments => 'Retire the old deployments',
            self::ProvisionDatastores => 'Provision the datastores',
            self::RestoreDatabases => 'Restore the databases',
            self::RestoreBuckets => 'Copy the buckets back',
            self::Deploy => 'Deploy the environments',
            self::CheckDomains => 'Check the domains',
            self::SyncCaddy => 'Sync Caddy',
        };
    }
}
