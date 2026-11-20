<?php

namespace App\Domains\Upgrades;

/**
 * Builds the Job that starts `opencloud upgrade` on the host. Pure: values in, a manifest array out.
 * The pod enters the namespaces of PID 1 and hands the command to systemd-run. So the upgrade runs as a service of the
 * host and goes on while k3s and the dashboard pod restart. "--collect" unloads a failed unit, so the next upgrade can
 * use the same unit name. The fixed unit name also stops a second upgrade while one runs.
 */
final class UpgradeManifests
{
    public const UNIT = 'opencloud-upgrade';

    /** @return array<string, mixed> */
    public static function job(string $target, string $image, int $startedAt): array
    {
        $labels = ['app.kubernetes.io/name' => self::UNIT, 'app.kubernetes.io/part-of' => 'opencloud'];

        return [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => ['name' => self::UNIT."-{$startedAt}", 'namespace' => 'opencloud', 'labels' => $labels],
            'spec' => [
                'backoffLimit' => 0,
                'ttlSecondsAfterFinished' => 3600,
                'template' => [
                    'metadata' => ['labels' => $labels],
                    'spec' => [
                        'restartPolicy' => 'Never',
                        'hostPID' => true,
                        'containers' => [[
                            'name' => 'upgrade',
                            'image' => $image,
                            'command' => [
                                'nsenter', '--target=1', '--mount', '--uts', '--ipc', '--net', '--pid', '--',
                                'systemd-run', '--unit='.self::UNIT, '--collect', '/usr/local/bin/opencloud', 'upgrade', $target,
                            ],
                            'securityContext' => ['privileged' => true],
                        ]],
                    ],
                ],
            ],
        ];
    }
}
