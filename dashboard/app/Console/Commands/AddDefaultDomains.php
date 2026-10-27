<?php

namespace App\Console\Commands;

use App\Domains\Environments\Enums\EnvironmentKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Routing\DefaultDomain;
use Illuminate\Console\Command;

class AddDefaultDomains extends Command
{
    protected $signature = 'opencloud:default-domains';

    protected $description = 'Give each permanent environment without one its free sslip.io domain';

    public function handle(DefaultDomain $defaultDomain): int
    {
        Environment::query()
            ->where('kind', EnvironmentKind::PERMANENT)
            ->with('application.cluster')
            ->orderBy('id')
            ->each(function (Environment $environment) use ($defaultDomain) {
                $domain = $defaultDomain->add($environment, null);

                if ($domain !== null) {
                    $this->info("Added {$domain->hostname} to {$environment->application->name} {$environment->name}.");
                }
            });

        return self::SUCCESS;
    }
}
