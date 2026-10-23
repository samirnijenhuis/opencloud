<?php

namespace App\Http\Controllers;

use App\Actions\AttachDatastore;
use App\Actions\DetachDatastore;
use App\Backups\BackupStatus;
use App\Datastores\DatastoreStatus;
use App\Datastores\Engines;
use App\Datastores\ManagedDatabase;
use App\Datastores\SqlEngine;
use App\Enums\Ability;
use App\Enums\DatastoreKind;
use App\Enums\DatastoreSource;
use App\Http\Controllers\Concerns\PresentsEnvironment;
use App\Http\Requests\AttachDatastoreRequest;
use App\Models\AuditEvent;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\DatastoreAttachment;
use App\Models\Environment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class DatastoreController extends Controller
{
    use PresentsEnvironment;

    private const BACKUPS_SHOWN = 7;

    private const RESTORE_ACTIONS = ['backup.restore_requested', 'backup.restored', 'backup.restore_failed'];

    public function index(Request $request, Environment $environment): Response
    {
        Gate::authorize('view', $environment);

        $role = $request->user()->role;
        $rows = $environment->datastoreAttachments()->with(['datastore.cluster', 'envVars'])->orderBy('id')->get()
            ->map(fn (DatastoreAttachment $attachment) => $this->present($attachment));
        $props = $this->environmentProps($environment, $request->user());

        return Inertia::render('environments/datastores', [
            ...$props,
            'can' => [
                ...$props['can'],
                'restore' => $role->allows(Ability::ChangeProtectedEnvironment),
                'manage_backup_target' => $role->allows(Ability::ManageCredentials),
            ],
            'attachments' => $rows,
            'available_kinds' => collect(DatastoreKind::cases())
                ->reject(fn (DatastoreKind $kind) => $rows->contains('kind', $kind))
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
        ]);
    }

    public function store(AttachDatastoreRequest $request, Environment $environment, AttachDatastore $attachDatastore): RedirectResponse
    {
        try {
            $attachDatastore->handle($environment, $request->user(), DatastoreKind::from($request->validated('kind')), $request->source());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['kind' => $e->getMessage()]);
        }

        return back();
    }

    public function destroy(Request $request, DatastoreAttachment $attachment, DetachDatastore $detachDatastore): RedirectResponse
    {
        Gate::authorize('update', $attachment->environment);

        if ($attachment->datastore->source === DatastoreSource::Managed) {
            abort_unless($request->user()->role->allows(Ability::ManageCredentials), 403);
            $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Confirm that DigitalOcean deletes the database and its data.']);
        }

        $detachDatastore->handle($attachment, $request->user());

        return back();
    }

    /** @return array<string, mixed> */
    private function present(DatastoreAttachment $attachment): array
    {
        $datastore = $attachment->datastore;
        $row = [
            'id' => $attachment->id,
            'kind' => $datastore->kind,
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

        if (! Engines::for($datastore->kind) instanceof SqlEngine) {
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
