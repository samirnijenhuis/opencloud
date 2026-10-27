<?php

namespace App\Domains\Environments\Actions;

use App\Domains\Environments\Models\Environment;
use App\Support\Kube\Kubectl;
use App\Support\Kube\Labels;
use App\Support\SecretMasker;
use InvalidArgumentException;

class ReadLogs
{
    /** @throws InvalidArgumentException when $process is not web or a process of the environment */
    public function execute(Environment $environment, string $process, int $lines = 200): string
    {
        $names = $environment->processNames();

        if (! in_array($process, $names, true)) {
            throw new InvalidArgumentException("{$process} is not a process of this environment. Use one of: ".implode(', ', $names).'.');
        }

        $output = Kubectl::for($environment->application->cluster)
            ->logs($environment->kubeNamespace(), Labels::process($environment, $process), $lines);

        return SecretMasker::forEnvironment($environment)->mask($output);
    }
}
