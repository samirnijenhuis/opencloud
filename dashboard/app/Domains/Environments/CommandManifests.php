<?php

namespace App\Domains\Environments;

use App\Domains\Deployments\EnvironmentManifests;
use App\Domains\Environments\Models\CommandRun;
use App\Domains\Environments\Models\Process;
use App\Support\Kube\Labels;

/**
 * Builds the Kubernetes Job of one command run. Pure: models in, a manifest array out.
 * The Job uses the image and the env Secret of the deployment that was live when the user ran the command.
 */
final readonly class CommandManifests
{
    public const PROCESS = Process::COMMAND;

    public const DEADLINE_SECONDS = 900;

    public function __construct(private CommandRun $run) {}

    /** @return array<string, mixed> */
    public function job(): array
    {
        $environment = $this->run->environment;
        $deployment = $this->run->deployment;
        $labels = [...Labels::environment($environment), Labels::PROCESS => self::PROCESS, Labels::DEPLOYMENT => (string) $deployment->id];
        $resources = ['cpu' => $environment->size->cpuMillicores().'m', 'memory' => $environment->size->memoryMib().'Mi'];
        $pullSecrets = $environment->application->cluster->registry() === null ? [] : ['imagePullSecrets' => [['name' => EnvironmentManifests::PULL_SECRET]]];

        return [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => ['name' => self::jobName($this->run), 'namespace' => $environment->kubeNamespace(), 'labels' => $labels],
            'spec' => [
                'backoffLimit' => 0,
                'activeDeadlineSeconds' => self::DEADLINE_SECONDS,
                'ttlSecondsAfterFinished' => 3600,
                'template' => [
                    'metadata' => ['labels' => $labels],
                    'spec' => [
                        'restartPolicy' => 'Never',
                        ...$pullSecrets,
                        ...EnvironmentManifests::POD_SECURITY,
                        'containers' => [[
                            'name' => self::PROCESS,
                            'image' => $deployment->image,
                            'command' => ['sh', '-c', $this->run->command],
                            'envFrom' => [['secretRef' => ['name' => EnvironmentManifests::secretName($deployment)]]],
                            'resources' => ['requests' => $resources, 'limits' => $resources],
                            'securityContext' => EnvironmentManifests::CONTAINER_SECURITY,
                        ]],
                    ],
                ],
            ],
        ];
    }

    public static function jobName(CommandRun $run): string
    {
        return "cmd-{$run->id}";
    }
}
