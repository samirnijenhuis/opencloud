<?php

namespace App\Build;

use App\Enums\ApplicationType;

final readonly class BuildResult
{
    public function __construct(
        public string $image,
        /** What the repository turned out to be. The process manifests follow it. */
        public ApplicationType $type,
    ) {}
}
