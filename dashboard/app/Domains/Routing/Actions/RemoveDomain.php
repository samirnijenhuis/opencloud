<?php

namespace App\Domains\Routing\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Members\Models\User;
use App\Domains\Routing\CloudflareDns;
use App\Domains\Routing\Jobs\SyncCaddy;
use App\Domains\Routing\Models\Domain;
use App\Domains\Routing\PrimaryDomain;
use Illuminate\Validation\ValidationException;

class RemoveDomain
{
    public function __construct(private CloudflareDns $cloudflare) {}

    /**
     * @throws ValidationException when Cloudflare refuses to delete the record that opencloud created
     */
    public function execute(Domain $domain, User $by): void
    {
        $this->cloudflare->remove($domain);

        $environment = $domain->environment;
        $wasPrimary = $domain->is(PrimaryDomain::of($environment->domains));
        $domain->delete();

        AuditEvent::record($by, 'domain.removed', $environment, ['hostname' => $domain->hostname, ...($wasPrimary ? ['primary' => true] : [])]);
        SyncCaddy::dispatch($environment->application->cluster)->afterCommit();
    }
}
