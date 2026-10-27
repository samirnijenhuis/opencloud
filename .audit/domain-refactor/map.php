<?php

$domains = [
    'Applications' => ['Actions/CreateApplication', 'Actions/UpdateApplicationEnvProfile', 'Models/Application', 'Policies/ApplicationPolicy', 'Enums/ApplicationType', 'Presets/EnvProfileKind'],
    'Audit' => ['Models/AuditEvent'],
    'Backups' => ['Actions/BackupNow', 'Actions/RestoreBackup', 'Actions/StoreBackupTarget', 'Actions/DeleteBackupTarget', 'Models/Backup', 'Models/BackupTarget', 'Jobs/BackupDashboard', 'Jobs/BackupDatabase', 'Jobs/BackupDatastore', 'Jobs/CopyBuckets', 'Jobs/RestoreDatabase', 'Backups/*'],
    'Builds' => ['Build/*'],
    'CloudProviders' => ['Actions/StoreProviderCredential', 'Actions/DeleteProviderCredential', 'Models/ProviderCredential', 'Policies/ProviderCredentialPolicy', 'CloudProviders/*', 'CloudProviders/Checks/*'],
    'Clusters' => ['Actions/ConnectCluster', 'Actions/CreateCluster', 'Actions/DestroyCluster', 'Actions/DisconnectCluster', 'Actions/ReinstallClusterParts', 'Models/Cluster', 'Policies/ClusterPolicy', 'Enums/ClusterKind', 'Jobs/InstallClusterParts', 'Jobs/ProvisionCluster', 'Jobs/TearDownCluster', 'Clusters/*', 'Kube/Manifests/ClusterPartsManifests' => 'ClusterPartsManifests'],
    'Datastores' => ['Actions/AttachDatastore', 'Actions/DetachDatastore', 'Actions/SetDefaultDatabase', 'Models/Datastore', 'Models/DatastoreAttachment', 'Enums/DatastoreKind', 'Enums/DatastoreSource', 'Enums/ResourceFamily', 'Jobs/ProvisionDatastoreAttachment', 'Jobs/ProvisionManagedDatabase', 'Jobs/RevokeDatastoreAccess', 'Jobs/TearDownManagedDatabase', 'Datastores/*', 'Datastores/Env/*', 'Kube/Manifests/DatastoreManifests' => 'DatastoreManifests'],
    'Deployments' => ['Actions/StartDeployment', 'Actions/EnableDeployHook', 'Actions/DisableDeployHook', 'Actions/RegenerateDeployHook', 'Actions/TriggerDeployHook', 'Models/Deployment', 'Enums/DeploymentStatus', 'Jobs/DeployPush', 'Jobs/FailStuckDeployments', 'Jobs/RunDeployment', 'Deploy/*', 'Environments/DeployHook' => 'DeployHook', 'Kube/Manifests/EnvironmentManifests' => 'EnvironmentManifests'],
    'Environments' => ['Actions/AddProcess', 'Actions/RemoveProcess', 'Actions/UpdateProcess', 'Actions/DeleteEnvVar', 'Actions/SetEnvVar', 'Actions/ReadLogs', 'Actions/RunCommand', 'Actions/SetEnvironmentColor', 'Actions/UpdateEnvironmentSettings', 'Models/Environment', 'Models/EnvVar', 'Models/Process', 'Models/CommandRun', 'Policies/EnvironmentPolicy', 'Enums/EnvVarAvailability', 'Enums/EnvVarSource', 'Enums/EnvironmentKind', 'Enums/Size', 'Jobs/ExecuteCommandRun', 'Environments/*', 'Kube/Manifests/CommandManifests' => 'CommandManifests'],
    'GitHub' => ['Actions/ConnectGitHubApp', 'Models/GithubApp', 'GitHub/*'],
    'Members' => ['Actions/AcceptInvite', 'Actions/IssueInvite', 'Actions/RevokeInvite', 'Actions/ChangeMemberRole', 'Actions/RemoveMember', 'Actions/CreateOwner', 'Models/User', 'Models/Invite', 'Policies/UserPolicy', 'Enums/Ability', 'Enums/Role'],
    'Previews' => ['Actions/UpdatePreviewSettings', 'Jobs/PostPreviewComment', 'Jobs/SyncPreview', 'Previews/*' => 'Actions/*', 'Observers/DeploymentObserver'],
    'Restore' => ['Jobs/RunRestore', 'Restore/*'],
    'Routing' => ['Actions/AddDomain', 'Actions/RemoveDomain', 'Actions/SetDashboardDomain', 'Actions/RemoveDashboardDomain', 'Models/Domain', 'Models/DashboardDomain', 'Enums/DnsMode', 'Jobs/CheckCertificates', 'Jobs/CheckDashboardDomain', 'Jobs/CheckDomain', 'Jobs/SyncCaddy', 'Caddy/*', 'Domains/*', 'Environments/DefaultDomain' => 'DefaultDomain'],
    'Upgrades' => ['Jobs/CheckLatestRelease'],
];

$support = ['Kube/*' => 'Support/Kube/*', 'Kube/Manifests/Labels' => 'Support/Kube/Labels', 'Tofu/*' => 'Support/Tofu/*'];
$keep = ['Console/', 'Http/', 'Providers/', 'Support/'];

$app = dirname(__DIR__, 2).'/dashboard/app/';
$files = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS)) as $file) {
    $files[] = substr(substr((string) $file, strlen($app)), 0, -4);
}
sort($files);

$kindFolders = ['Actions', 'Models', 'Policies', 'Enums', 'Jobs', 'Observers'];
$kindOf = function (string $old): ?string {
    $source = file_get_contents(dirname(__DIR__, 2).'/dashboard/app/'.$old.'.php');
    if (preg_match('/^enum\s/m', $source)) {
        return 'Enums';
    }
    if (preg_match('/^(final\s+)?class\s+\w+\s+extends\s+\w*Exception\b/m', $source)) {
        return 'Exceptions';
    }

    return null;
};

$rules = [];
foreach ($domains as $domain => $entries) {
    foreach ($entries as $key => $value) {
        [$pattern, $target] = is_int($key) ? [$value, null] : [$key, $value];
        $rules[$pattern] = [$domain, $target];
    }
}

$map = [];
$unmatched = [];
foreach ($files as $old) {
    if (array_filter($keep, fn (string $prefix): bool => str_starts_with($old, $prefix))) {
        continue;
    }
    $dir = dirname($old);
    $name = basename($old);
    $hit = $rules[$old] ?? $rules[$dir.'/*'] ?? null;
    if ($hit !== null) {
        [$domain, $target] = $hit;
        $first = explode('/', $old)[0];
        if ($target !== null && str_ends_with($target, '/*')) {
            $relative = substr($target, 0, -1).$name;
        } elseif ($target !== null) {
            $relative = $target;
        } elseif (in_array($first, $kindFolders, true)) {
            $relative = $first.'/'.$name;
        } else {
            $sub = substr($dir, strlen($first));
            $relative = ltrim($sub.'/'.$name, '/');
        }
        $kind = $kindOf($old);
        if ($kind !== null && ! str_starts_with($relative, $kind.'/')) {
            $relative = $kind.'/'.basename($relative);
        }
        $map[$old] = "Domains/{$domain}/{$relative}";
        continue;
    }
    $supportHit = $support[$old] ?? $support[$dir.'/*'] ?? null;
    if ($supportHit !== null) {
        $map[$old] = str_ends_with($supportHit, '/*') ? substr($supportHit, 0, -1).$name : $supportHit;
        continue;
    }
    $unmatched[] = $old;
}

if ($unmatched !== []) {
    fwrite(STDERR, "Unmatched:\n".implode("\n", $unmatched)."\n");
    exit(1);
}
$targets = array_count_values($map);
$duplicates = array_keys(array_filter($targets, fn (int $count): bool => $count > 1));
if ($duplicates !== []) {
    fwrite(STDERR, "Duplicate targets:\n".implode("\n", $duplicates)."\n");
    exit(1);
}

return $map;
