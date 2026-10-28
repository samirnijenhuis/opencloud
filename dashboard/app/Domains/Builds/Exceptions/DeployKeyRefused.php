<?php

namespace App\Domains\Builds\Exceptions;

use RuntimeException;

/** GitHub does not know the deploy key of the application. The message ends with the step that fixes it. */
final class DeployKeyRefused extends RuntimeException
{
    public const STEP = 'GitHub refused the deploy key. Add the public key to the repository under Settings, Deploy keys.';

    public function __construct(string $gitError)
    {
        parent::__construct("{$gitError}\n".self::STEP);
    }
}
