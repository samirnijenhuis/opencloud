<?php

namespace App\Actions;

use App\Jobs\CheckDomain;
use App\Models\AuditEvent;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\User;

class AddDomain
{
    public function handle(Environment $environment, User $by, string $hostname): Domain
    {
        $hostname = rtrim(strtolower(trim($hostname)), '.');
        $domain = $environment->domains()->create(['hostname' => $hostname]);

        AuditEvent::record($by, 'domain.added', $domain, ['environment_id' => $environment->id, 'hostname' => $hostname]);
        CheckDomain::dispatch($domain)->afterCommit();

        return $domain;
    }
}
