<?php

namespace App\Actions;

use App\Domains\CloudflareDns;
use App\Jobs\SyncCaddy;
use App\Models\AuditEvent;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RemoveDomain
{
    public function __construct(private CloudflareDns $cloudflare) {}

    /**
     * @throws ValidationException when Cloudflare refuses to delete the record that opencloud created
     */
    public function handle(Domain $domain, User $by): void
    {
        $this->cloudflare->remove($domain);

        $environment = $domain->environment;
        $domain->delete();

        AuditEvent::record($by, 'domain.removed', $environment, ['hostname' => $domain->hostname]);
        SyncCaddy::dispatch($environment->application->cluster)->afterCommit();
    }
}
