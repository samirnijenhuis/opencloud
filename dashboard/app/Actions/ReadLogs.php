<?php

namespace App\Actions;

use App\Kube\Kubectl;
use App\Kube\Manifests\Labels;
use App\Kube\Manifests\Process;
use App\Models\Environment;
use App\Support\SecretMasker;
use InvalidArgumentException;

class ReadLogs
{
    /** @throws InvalidArgumentException when $process is not web, worker, or scheduler */
    public function handle(Environment $environment, string $process, int $lines = 200): string
    {
        $name = Process::tryFrom($process);

        if (! in_array($name, [Process::Web, Process::Worker, Process::Scheduler], true)) {
            throw new InvalidArgumentException("{$process} is not a process. Use web, worker, or scheduler.");
        }

        $output = Kubectl::for($environment->application->cluster)
            ->logs($environment->kubeNamespace(), Labels::process($environment, $name), $lines);

        return SecretMasker::forEnvironment($environment)->mask($output);
    }
}
