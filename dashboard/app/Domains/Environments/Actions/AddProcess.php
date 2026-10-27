<?php

namespace App\Domains\Environments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Enums\ProcessKind;
use App\Domains\Environments\Enums\Size;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\Process;
use App\Domains\Members\Models\User;
use Illuminate\Validation\ValidationException;

class AddProcess
{
    /**
     * Adds a process to the environment. It starts on the next deploy. A scheduled process keeps no instances.
     *
     * @throws ValidationException when a value breaks a rule or another process has the name
     */
    public function execute(
        Environment $environment,
        User $by,
        string $name,
        string $command,
        ProcessKind $kind,
        ?string $schedule,
        Size $size,
        ?int $minInstances,
        ?int $maxInstances,
        ?int $cpuTarget,
    ): Process {
        $attributes = Process::validate($environment, $name, $command, $kind, $schedule, $size, $minInstances, $maxInstances, $cpuTarget);
        $process = $environment->processes()->create($attributes);

        AuditEvent::record($by, 'process.added', $process, ['environment_id' => $environment->id, ...$attributes]);

        return $process;
    }
}
