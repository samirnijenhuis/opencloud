<?php

namespace App\Domains\Datastores\Env;

/**
 * One env value that a profile writes for an attachment. A secret is never sent to the browser.
 */
final readonly class EnvValue
{
    private function __construct(
        public string $value,
        public bool $secret,
    ) {}

    public static function plain(string $value): self
    {
        return new self($value, false);
    }

    public static function secret(string $value): self
    {
        return new self($value, true);
    }
}
