<?php

namespace App\Domains\Datastores\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Datastores\AttachmentEnv;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Datastores\Provisioners;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Jobs\SyncCaddy;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

class SetBucketVisibility
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * Makes a bucket public or private. The provisioner sets the bucket policy again, Caddy adds or removes the route of
     * the public address, and the app reads the new env values on the next deploy.
     *
     * @throws ValidationException when the source or the cluster cannot serve a public bucket
     */
    public function execute(DatastoreAttachment $attachment, User $by, bool $public): void
    {
        if ($attachment->is_public === $public) {
            return;
        }

        $provisioner = Provisioners::of($attachment->datastore);
        $cluster = $attachment->environment->application->cluster;
        $refusal = $public ? $provisioner->publicRefusal($cluster) : null;

        if ($refusal !== null) {
            throw ValidationException::withMessages(['public' => $refusal]);
        }

        // The env values of the attachment follow its visibility, so both change or neither does.
        $this->db->transaction(function () use ($attachment, $by, $public, $provisioner, $cluster) {
            $attachment->update(['is_public' => $public]);
            AttachmentEnv::write($attachment);

            AuditEvent::record($by, 'datastore.visibility_changed', $attachment, [
                'environment_id' => $attachment->environment_id,
                'name' => $attachment->name,
                'public' => $public,
            ]);

            $provisioner->retry($attachment);
            SyncCaddy::dispatch($cluster)->afterCommit();
        });
    }
}
