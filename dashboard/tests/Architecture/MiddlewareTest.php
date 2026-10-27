<?php

declare(strict_types=1);

namespace Tests\Architecture;

final class MiddlewareTest extends ArchitectureTestCase
{
    public function test_middleware_has_no_middleware_suffix(): void
    {
        $this->assertMatchesBaseline(
            'middleware.no-middleware-suffix',
            'Name middleware by what it does, without a Middleware suffix: HandleInertiaRequests. See .claude/rules/naming.md.',
            $this->namedWith($this->layer(), 'Middleware'),
        );
    }
}
