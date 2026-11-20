<?php

namespace Tests\Domains\Upgrades;

use App\Domains\Upgrades\UpgradeManifests;
use PHPUnit\Framework\TestCase;

class UpgradeManifestsTest extends TestCase
{
    public function test_the_job_hands_the_upgrade_to_systemd_on_the_host(): void
    {
        $labels = ['app.kubernetes.io/name' => 'opencloud-upgrade', 'app.kubernetes.io/part-of' => 'opencloud'];

        $this->assertSame([
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => ['name' => 'opencloud-upgrade-1795168800', 'namespace' => 'opencloud', 'labels' => $labels],
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
                            'image' => 'ghcr.io/opencloud-dev/opencloud:v0.5.3',
                            'command' => [
                                'nsenter', '--target=1', '--mount', '--uts', '--ipc', '--net', '--pid', '--',
                                'systemd-run', '--unit=opencloud-upgrade', '--collect', '/usr/local/bin/opencloud', 'upgrade', 'v0.6.0',
                            ],
                            'securityContext' => ['privileged' => true],
                        ]],
                    ],
                ],
            ],
        ], UpgradeManifests::job('v0.6.0', 'ghcr.io/opencloud-dev/opencloud:v0.5.3', 1795168800));
    }
}
