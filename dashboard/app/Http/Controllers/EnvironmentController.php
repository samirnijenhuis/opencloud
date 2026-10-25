<?php

namespace App\Http\Controllers;

use App\Actions\UpdateEnvironmentSettings;
use App\Backups\BackupStatus;
use App\Datastores\DatabaseConnection;
use App\Datastores\DatastoreStatus;
use App\Datastores\ManagedDatabase;
use App\Domains\CloudflareDns;
use App\Enums\Ability;
use App\Enums\DatastoreKind;
use App\Enums\DatastoreSource;
use App\Enums\EnvVarSource;
use App\Enums\Role;
use App\Enums\Size;
use App\Http\Controllers\Concerns\PresentsEnvironment;
use App\Http\Requests\UpdateEnvironmentSettingsRequest;
use App\Models\AuditEvent;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\DatastoreAttachment;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvVar;
use App\Models\Process;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class EnvironmentController extends Controller
{
    use PresentsEnvironment;

    private const DEPLOYMENTS_SHOWN = 3;

    private const BACKUPS_SHOWN = 7;

    private const RESTORE_ACTIONS = ['backup.restore_requested', 'backup.restored', 'backup.restore_failed'];

    /** The canvas: the network, the app, and the resources of one environment, each with the data its sheet edits. */
    public function show(Request $request, Environment $environment): Response
    {
        Gate::authorize('view', $environment);

        $props = $this->environmentProps($environment, $request->user());
        $role = $request->user()->role;
        $cluster = $environment->application->cluster;

        return Inertia::render('environments/show', [
            ...$props,
            'can' => [
                ...$props['can'],
                'restore' => $role->allows(Ability::ChangeProtectedEnvironment),
                'manage_credentials' => $role->allows(Ability::ManageCredentials),
            ],
            'cluster' => ['id' => $cluster->id, 'name' => $cluster->name, 'kind' => $cluster->kind, 'ingress_ip' => $cluster->ingress_ip],
            'tls_issuer' => config('opencloud.tls_issuer'),
            'compute' => $environment->only(['size', 'min_instances', 'max_instances', 'cpu_target', 'health_path']),
            'sizes' => array_map(fn (Size $size) => [
                'value' => $size->value,
                'cpu_millicores' => $size->cpuMillicores(),
                'memory_mib' => $size->memoryMib(),
            ], Size::cases()),
            'processes' => $environment->processes->map(fn (Process $process) => $process->only(['id', 'name', 'command', 'instances', 'exclusive'])),
            'domains' => $environment->domains()->orderBy('id')->get()->map(fn (Domain $domain) => [
                'id' => $domain->id,
                'hostname' => $domain->hostname,
                'generated' => $domain->generated,
                'dns_status' => $domain->dns_status,
                'dns_mode' => $domain->dns_mode,
                'dns_found' => $domain->dns_found ?? [],
                'certificate_status' => $domain->certificate_status,
                'dns_checked_at' => $domain->dns_checked_at?->toIso8601String(),
            ]),
            'cloudflare_zones' => array_column(CloudflareDns::credential()?->details['zones'] ?? [], 'name'),
            'datastores' => $this->datastores($environment, $role),
            'deployments' => $environment->deployments()->with('triggeredBy:id,name')->latest('id')->limit(self::DEPLOYMENTS_SHOWN)->get()
                ->map(fn (Deployment $deployment) => $this->deploymentProps($deployment)),
        ]);
    }

    /** The runs load as JSON, so the page can poll them. A user without the right to read logs sees why the page is empty. */
    public function commands(Request $request, Environment $environment): Response
    {
        Gate::authorize('view', $environment);

        $props = $this->environmentProps($environment, $request->user());

        return Inertia::render('environments/commands', [
            ...$props,
            'can' => [...$props['can'], 'read_logs' => $request->user()->role->allows(Ability::ReadLogs)],
        ]);
    }

    public function settings(Request $request, Environment $environment): Response
    {
        Gate::authorize('view', $environment);

        return Inertia::render('environments/settings', [
            ...$this->environmentProps($environment, $request->user()),
            'settings' => [
                'branch' => $environment->branch,
                'auto_deploy' => $environment->auto_deploy,
                'build_command' => $environment->build_command,
                'deploy_commands' => $environment->deploy_commands,
            ],
            // Only a plain user value goes to the browser. A secret and a datastore value never do.
            'envVars' => $environment->envVars()->orderBy('key')->get()->map(fn (EnvVar $envVar) => [
                'id' => $envVar->id,
                'key' => $envVar->key,
                'source' => $envVar->source,
                'is_secret' => $envVar->is_secret,
                'available_at' => $envVar->available_at,
                'value' => $envVar->source === EnvVarSource::User && ! $envVar->is_secret ? $envVar->value : null,
            ]),
        ]);
    }

    public function update(UpdateEnvironmentSettingsRequest $request, Environment $environment, UpdateEnvironmentSettings $updateSettings): RedirectResponse
    {
        try {
            $updateSettings->handle($environment, $request->user(), $request->settings());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['max_instances' => $e->getMessage()]);
        }

        return back();
    }

    /** @return array<string, mixed> */
    private function datastores(Environment $environment, Role $role): array
    {
        $rows = $environment->datastoreAttachments()->with(['datastore.cluster', 'envVars'])->orderBy('id')->get()
            ->map(fn (DatastoreAttachment $attachment) => $this->presentAttachment($attachment));

        return [
            'attachments' => $rows,
            'available_kinds' => collect(DatastoreKind::cases())
                ->reject(fn (DatastoreKind $kind) => ! $kind->isSql() && $rows->contains('kind', $kind))
                ->values(),
            'has_backup_target' => BackupTarget::query()->exists(),
            'polling' => $rows->contains(fn (array $row) => in_array($row['status'], [DatastoreStatus::Pending, DatastoreStatus::Provisioning], true)
                || $row['server']['status'] === DatastoreStatus::Destroying
                || collect($row['backups'] ?? [])->contains('status', BackupStatus::Running)
                || ($row['restore']['status'] ?? null) === 'running'),
            'managed' => [
                'available' => $role->allows(Ability::ManageCredentials) && ManagedDatabase::availableOn($environment->application->cluster),
                'pricing_url' => ManagedDatabase::PRICING_URL,
                'size' => config('opencloud.managed_database_size'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function presentAttachment(DatastoreAttachment $attachment): array
    {
        $datastore = $attachment->datastore;
        $row = [
            'id' => $attachment->id,
            'kind' => $datastore->kind,
            'name' => $attachment->name,
            'is_default' => $attachment->is_default,
            // The default sets the DB_* values that Laravel reads by itself. Each other database needs this line.
            'config_line' => $attachment->name !== null && ! $attachment->is_default ? DatabaseConnection::configLine($datastore->kind, $attachment->name) : null,
            'status' => $attachment->status,
            'error' => $attachment->error,
            'server' => [
                'cluster' => $datastore->cluster->name,
                'version' => $datastore->version,
                'status' => $datastore->status,
                'error' => $datastore->error,
            ],
            'env_keys' => $attachment->envVars->pluck('key')->sort()->values(),
        ];

        // DigitalOcean backs up a managed database itself, and the nightly dump cannot reach it.
        if ($datastore->source === DatastoreSource::Managed) {
            return [...$row, 'managed' => true, 'tofu_log' => $datastore->tofu_log];
        }

        if (! $datastore->kind->isSql()) {
            return $row;
        }

        $backups = Backup::query()
            ->where('datastore_id', $attachment->datastore_id)
            ->where('database_name', $attachment->database_name)
            ->latest('id');

        return [
            ...$row,
            'backups' => (clone $backups)->limit(self::BACKUPS_SHOWN)->get()->map(fn (Backup $backup) => [
                'id' => $backup->id,
                'status' => $backup->status,
                'size' => $backup->size,
                'location' => $backup->disk === 'local' ? 'local' : 's3',
                'error' => $backup->error,
                'created_at' => $backup->created_at->toIso8601String(),
            ])->all(),
            'restore' => $this->latestRestore($backups->pluck('id')->all()),
        ];
    }

    /**
     * @param  list<int>  $backupIds
     * @return array{status: 'running'|'restored'|'failed', at: string, error: ?string}|null
     */
    private function latestRestore(array $backupIds): ?array
    {
        $event = AuditEvent::query()
            ->where('subject_type', (new Backup)->getMorphClass())
            ->whereIn('subject_id', $backupIds)
            ->whereIn('action', self::RESTORE_ACTIONS)
            ->latest('id')
            ->first();

        if ($event === null) {
            return null;
        }

        return [
            'status' => match ($event->action) {
                'backup.restored' => 'restored',
                'backup.restore_failed' => 'failed',
                default => 'running',
            },
            'at' => $event->created_at->toIso8601String(),
            'error' => $event->action === 'backup.restore_failed' ? ($event->context['error'] ?? null) : null,
        ];
    }
}
