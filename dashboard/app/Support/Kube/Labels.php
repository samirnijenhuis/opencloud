<?php

namespace App\Support\Kube;

use App\Domains\Deployments\Models\Deployment;
use App\Domains\Environments\Models\Environment;

final class Labels
{
    public const APPLICATION = 'opencloud.dev/application';

    public const ENVIRONMENT = 'opencloud.dev/environment';

    public const PROCESS = 'opencloud.dev/process';

    public const DEPLOYMENT = 'opencloud.dev/deployment';

    public const DATASTORE = 'opencloud.dev/datastore';

    public const ATTACHMENT = 'opencloud.dev/attachment';

    /** @return array<string, string> */
    public static function environment(Environment $environment): array
    {
        return [
            self::APPLICATION => (string) $environment->application_id,
            self::ENVIRONMENT => (string) $environment->id,
        ];
    }

    /** @return array<string, string> */
    public static function process(Environment $environment, string $process): array
    {
        return [...self::environment($environment), self::PROCESS => $process];
    }

    /** @return array<string, string> */
    public static function deployment(Environment $environment, string $process, Deployment $deployment): array
    {
        return [...self::process($environment, $process), self::DEPLOYMENT => (string) $deployment->id];
    }
}
