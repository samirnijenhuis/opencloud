<?php

namespace App\Domains\Routing\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Models\User;
use App\Domains\Routing\Models\Domain;
use Illuminate\Database\ConnectionInterface;

class SetPrimaryDomain
{
    public function __construct(private ConnectionInterface $db) {}

    /** Makes the domain the primary domain of its environment. The app reads the new APP_URL on the next deploy. */
    public function execute(Domain $domain, User $by): void
    {
        $this->db->transaction(function () use ($domain, $by) {
            $previous = Domain::query()
                ->where('environment_id', $domain->environment_id)
                ->where('is_primary', true)
                ->first();

            if ($previous?->is($domain)) {
                return;
            }

            // One primary domain per environment is a unique index, so the old primary domain lets go first.
            $previous?->update(['is_primary' => false]);
            $domain->update(['is_primary' => true]);

            AuditEvent::record($by, 'domain.primary_changed', $domain, [
                'environment_id' => $domain->environment_id,
                'hostname' => $domain->hostname,
                'previous' => $previous?->hostname,
            ]);
        });
    }
}
