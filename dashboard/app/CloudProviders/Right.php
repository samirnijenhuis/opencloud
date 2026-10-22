<?php

namespace App\CloudProviders;

final readonly class Right
{
    public function __construct(public string $id, public string $label, public bool $optional = false) {}
}
