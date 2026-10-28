<?php

namespace App\Domains\Environments;

final readonly class ParsedDotenv
{
    /**
     * @param  array<string, string>  $values  the value of each key
     * @param  array<string, int>  $lines  the line number of each key
     * @param  list<string>  $errors  one message for each bad line
     */
    public function __construct(
        public array $values,
        public array $lines,
        public array $errors,
    ) {}
}
