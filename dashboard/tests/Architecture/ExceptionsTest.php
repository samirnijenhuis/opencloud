<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Architecture\Elements\ObjectDescription;
use Throwable;

final class ExceptionsTest extends ArchitectureTestCase
{
    public function test_exceptions_live_in_a_domain_exceptions_directory(): void
    {
        $this->assertMatchesBaseline(
            'exceptions.placement',
            'Put an exception in app/Domains/{Domain}/Exceptions. A gateway in app/Support keeps its exception next to the gateway class. See .claude/rules/architecture.md.',
            $this->placedOutside(
                $this->layer()->leave(fn (ObjectDescription $object): bool => $object->reflectionClass->implementsInterface(Throwable::class)
                    && ! str_starts_with($object->name, 'App\\Support\\')),
                '/^App\\\\Domains\\\\(\w+\\\\)+Exceptions\\\\\w+$/',
            ),
        );
    }
}
