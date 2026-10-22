<?php

namespace App\Kube\Manifests;

enum Process: string
{
    case Web = 'web';
    case Worker = 'worker';
    case Scheduler = 'scheduler';
    /** The one-off Job that runs the deploy commands. */
    case Deploy = 'deploy';
}
