<?php

namespace App\Http\Controllers;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Backups\Enums\BackupStatus;
use App\Domains\Backups\Models\Backup;
use App\Domains\Backups\Models\BackupTarget;
use App\Domains\CloudProviders\Providers;
use App\Domains\Clusters\Models\Cluster;
use App\Domains\Datastores\AttachmentEnv;
use App\Domains\Datastores\Capabilities;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Enums\DatastoreStatus;
use App\Domains\Datastores\Enums\ResourceFamily;
use App\Domains\Datastores\ExternalConnectionForm;
use App\Domains\Datastores\ExternalField;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Datastores\Provisioners;
use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Actions\UpdateEnvironmentSettings;
use App\Domains\Environments\DotenvText;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Enums\Size;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Environments\Models\Process;
use App\Domains\Members\Enums\Ability;
use App\Domains\Members\Enums\Role;
use App\Domains\Routing\CloudflareDns;
use App\Domains\Routing\Models\Domain;
use App\Domains\Routing\PrimaryDomain;
use App\Http\Controllers\Concerns\PresentsEnvironment;
use App\Http\Requests\AttachDatastoreRequest;
use App\Http\Requests\UpdateEnvironmentSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

    /** The name of a kind when its capabilities give no label. */
    private const KIND_NAMES = ['mysql' => 'MySQL', 'postgres' => 'Postgres', 'valkey' => 'Valkey', 'rustfs' => 'RustFS bucket'];

    /** What each family is for, whatever the source. */
    private const PURPOSES = ['database' => 'A SQL database', 'key_value' => 'Cache, queues, and sessions', 'bucket' => 'S3 storage for files'];

    /** The name field of the add dialog for each named family, and what the dialog says about the first one and a later one. */
    private const NAME_FIELDS = [
        'database' => [
            'label' => 'Connection name',
            'placeholder' => 'reports',
            'first' => 'This is the first database of the environment, so it is the default.',
            'later' => 'The default database keeps its env values. The app reaches this database through one URL value.',
        ],
        'bucket' => [
            'label' => 'Name',
            'placeholder' => 'uploads',
            'first' => 'This is the first bucket of the environment, so it is the default.',
            'later' => 'The default bucket keeps its env values. This bucket gets its own env values with its name as a prefix.',
        ],
    ];

    /** The canvas: the network, the app, and the resources of one environment, each with the data its sheet edits. */
    public function show(Request $request, Environment $environment): Response
    {
        Gate::authorize('view', $environment);

        $props = $this->environmentProps($environment, $request->user());
        $role = $request->user()->role;
        $cluster = $environment->application->cluster;
        $domains = $environment->domains()->orderBy('id')->get();
        $primary = PrimaryDomain::of($domains);

        return Inertia::render('environments/show', [
            ...$props,
            'can' => [
                ...$props['can'],
                'restore' => $role->allows(Ability::CHANGE_PROTECTED_ENVIRONMENT),
                'manage_credentials' => $role->allows(Ability::MANAGE_CREDENTIALS),
            ],
            'cluster' => ['id' => $cluster->id, 'name' => $cluster->name, 'kind' => $cluster->kind, 'ingress_ip' => $cluster->ingress_ip],
            'tls_issuer' => config('opencloud.tls_issuer'),
            'compute' => $environment->only(['size', 'min_instances', 'max_instances', 'cpu_target', 'health_path']),
            'sizes' => array_map(fn (Size $size) => [
                'value' => $size->value,
                'cpu_millicores' => $size->cpuMillicores(),
                'memory_mib' => $size->memoryMib(),
            ], Size::cases()),
            'processes' => $environment->processes->map(fn (Process $process) => $process->only([
                'id', 'name', 'kind', 'command', 'schedule', 'size', 'min_instances', 'max_instances', 'cpu_target',
            ])),
            'domains' => $domains->map(fn (Domain $domain) => [
                'id' => $domain->id,
                'hostname' => $domain->hostname,
                'generated' => $domain->generated,
                'primary' => $domain->is($primary),
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
            'can' => [...$props['can'], 'read_logs' => $request->user()->role->allows(Ability::READ_LOGS)],
        ]);
    }

    public function settings(Request $request, Environment $environment, DotenvText $dotenv): Response
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
            'envText' => $dotenv->format(
                $environment->envVars()->where('source', EnvVarSource::USER)->where('is_secret', false)->orderBy('key')->pluck('value', 'key')->all(),
            ),
            // Only a plain user value goes to the browser. A secret and a datastore value never do.
            'envVars' => $environment->envVars()->with('datastoreAttachment.datastore')->orderBy('key')->get()->map(fn (EnvVar $envVar) => [
                'id' => $envVar->id,
                'key' => $envVar->key,
                'source' => $envVar->source,
                'is_secret' => $envVar->is_secret,
                'available_at' => $envVar->available_at,
                'resource' => $envVar->datastoreAttachment === null ? null : $this->resourceName($envVar->datastoreAttachment),
                'value' => $envVar->source === EnvVarSource::USER && ! $envVar->is_secret ? $envVar->value : null,
            ]),
        ]);
    }

    public function update(UpdateEnvironmentSettingsRequest $request, Environment $environment, UpdateEnvironmentSettings $updateSettings): RedirectResponse
    {
        try {
            $updateSettings->execute($environment, $request->user(), $request->settings());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['max_instances' => $e->getMessage()]);
        }

        return back();
    }

    /** @return array<string, mixed> */
    private function datastores(Environment $environment, Role $role): array
    {
        $attachments = $environment->datastoreAttachments()->with(['datastore.cluster', 'envVars'])->orderBy('id')->get();
        $rows = $attachments->map(fn (DatastoreAttachment $attachment) => $this->presentAttachment($attachment, $role));

        return [
            'attachments' => $rows,
            'offers' => $this->offers($environment->application->cluster, $role, $attachments),
            'has_backup_target' => BackupTarget::query()->exists(),
            'polling' => $rows->contains(fn (array $row) => in_array($row['status'], [DatastoreStatus::PENDING, DatastoreStatus::PROVISIONING], true)
                || $row['server']['status'] === DatastoreStatus::DESTROYING
                || collect($row['backups'] ?? [])->contains('status', BackupStatus::RUNNING)
                || ($row['restore']['status'] ?? null) === 'running'),
        ];
    }

    /**
     * Each kind and source of the registry that the environment can still add, with the reason when this user cannot
     * add it here. The add menu shows exactly this list, so a new arm of the registry needs no change in the dashboard.
     *
     * @param  Collection<int, DatastoreAttachment>  $attachments
     * @return list<array<string, mixed>>
     */
    private function offers(Cluster $cluster, Role $role, Collection $attachments): array
    {
        $taken = $attachments->map(fn (DatastoreAttachment $attachment) => $attachment->datastore->kind->family());
        $provider = self::providerName($cluster);
        $offered = [];
        $offers = [];

        foreach (Provisioners::table() as ['kind' => $kind, 'source' => $source, 'provisioner' => $provisioner]) {
            $family = $kind->family();

            // An environment has one key-value store, and as many databases and buckets as it needs.
            if (! $family->isNamed() && $taken->contains($family)) {
                continue;
            }

            $capabilities = $provisioner->capabilities();
            $offered[$source->value] ??= Provisioners::offered($source, $cluster);

            $offers[] = [
                'kind' => $kind,
                'source' => $source,
                'family' => $family,
                'name_field' => $family->isNamed() ? self::nameField($family, $attachments) : null,
                'label' => self::label($kind, $source, $capabilities),
                'description' => self::PURPOSES[$family->value],
                'group' => match ($source) {
                    DatastoreSource::STANDARD => "In the cluster {$cluster->name}",
                    DatastoreSource::MANAGED => "Managed by {$provider}",
                    DatastoreSource::EXTERNAL => 'Bring your own',
                },
                'note' => match ($source) {
                    DatastoreSource::STANDARD => null,
                    DatastoreSource::MANAGED => ucfirst("{$provider} runs it and charges your account for it."),
                    DatastoreSource::EXTERNAL => 'Opencloud stores the address and sets the env values. It does not back up the data.',
                },
                'refusal' => in_array($kind, $offered[$source->value], true)
                    ? AttachDatastoreRequest::sourceRefusal($role, $source)
                    : $capabilities->notOffered ?? $provisioner->refusalOn($cluster),
                'fields' => $source === DatastoreSource::EXTERNAL
                    ? array_map(fn (ExternalField $field) => $field->toArray(), ExternalConnectionForm::fields($kind))
                    : [],
            ];
        }

        return $offers;
    }

    /**
     * The first database or bucket of an environment is the default, so its dialog starts with the name "main".
     *
     * @param  Collection<int, DatastoreAttachment>  $attachments
     * @return array{label: string, placeholder: string, value: string, description: string}
     */
    private static function nameField(ResourceFamily $family, Collection $attachments): array
    {
        $texts = self::NAME_FIELDS[$family->value];
        $first = ! $attachments->contains(fn (DatastoreAttachment $attachment) => $attachment->family === $family && $attachment->name !== null);

        return [
            'label' => $texts['label'],
            'placeholder' => $texts['placeholder'],
            'value' => $first ? ResourceFamily::FIRST_NAME : '',
            'description' => $first ? $texts['first'] : $texts['later'],
        ];
    }

    /** @return array<string, mixed> */
    private function presentAttachment(DatastoreAttachment $attachment, Role $role): array
    {
        $datastore = $attachment->datastore;
        $capabilities = Provisioners::of($datastore)->capabilities();
        $row = [
            'id' => $attachment->id,
            'kind' => $datastore->kind,
            'source' => $datastore->source,
            'family' => $attachment->family,
            'label' => self::label($datastore->kind, $datastore->source, $capabilities),
            'name' => $attachment->name,
            'is_default' => $attachment->is_default,
            // The default sets the values that Laravel reads by itself. Each other database or bucket needs this line.
            'config_line' => AttachmentEnv::configLine($attachment),
            'status' => $attachment->status,
            'error' => $attachment->error,
            // The address of an external server is what the user typed, so its host may show. Its secret never does.
            'runs_on' => match ($datastore->source) {
                DatastoreSource::STANDARD => $datastore->cluster->name,
                DatastoreSource::MANAGED => self::providerName($datastore->cluster),
                DatastoreSource::EXTERNAL => parse_url((string) $attachment->host, PHP_URL_HOST) ?: (string) $attachment->host,
            },
            'server' => [
                'cluster' => $datastore->cluster?->name,
                'version' => $datastore->version,
                'status' => $datastore->status,
                'error' => $datastore->error,
            ],
            'env_keys' => $attachment->envVars->pluck('key')->sort()->values(),
            'detach_refusal' => $datastore->source === DatastoreSource::MANAGED && ! $role->allows(Ability::MANAGE_CREDENTIALS)
                ? 'Only an owner can destroy a managed resource.'
                : null,
        ];

        // The provider backs up a managed resource itself, and the nightly dump cannot reach it.
        if ($datastore->source === DatastoreSource::MANAGED) {
            return [...$row, 'managed' => true, 'tofu_log' => $datastore->tofu_log];
        }

        if ($capabilities->dumps === null) {
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

    /** The name that the canvas shows for a resource: its label and its connection name, for example "Postgres main". */
    private function resourceName(DatastoreAttachment $attachment): string
    {
        $datastore = $attachment->datastore;
        $label = self::label($datastore->kind, $datastore->source, Provisioners::of($datastore)->capabilities());

        return trim("{$label} {$attachment->name}");
    }

    private static function label(DatastoreKind $kind, DatastoreSource $source, Capabilities $capabilities): string
    {
        return $capabilities->label ?? match ($source) {
            DatastoreSource::MANAGED => 'Managed '.self::KIND_NAMES[$kind->value],
            default => self::KIND_NAMES[$kind->value],
        };
    }

    /** The provider that runs a managed resource for an environment on the cluster. */
    private static function providerName(?Cluster $cluster): string
    {
        $guide = $cluster?->provider === null ? null : Providers::all()[$cluster->provider] ?? null;

        return $guide->label ?? 'a cloud provider';
    }
}
