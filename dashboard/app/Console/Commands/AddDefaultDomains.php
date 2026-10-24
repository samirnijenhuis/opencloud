<?php

namespace App\Console\Commands;

use App\Enums\EnvironmentKind;
use App\Environments\DefaultDomain;
use App\Models\Environment;
use Illuminate\Console\Command;

class AddDefaultDomains extends Command
{
    protected $signature = 'opencloud:default-domains';

    protected $description = 'Give each permanent environment without one its free sslip.io domain';

    public function handle(DefaultDomain $defaultDomain): int
    {
        Environment::query()
            ->where('kind', EnvironmentKind::Permanent)
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
