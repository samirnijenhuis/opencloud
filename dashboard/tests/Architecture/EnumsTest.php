<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PhpParser\Node;
use PHPUnit\Architecture\Elements\ObjectDescription;
use PHPUnit\Architecture\Enums\ObjectType;

final class EnumsTest extends ArchitectureTestCase
{
    public function test_enum_cases_are_upper_case(): void
    {
        $this->assertMatchesBaseline(
            'enums.upper-case-cases',
            'Name enum cases in UPPER_SNAKE_CASE: MANAGE_CREDENTIALS, PREVIEW_TEMPLATE. See .claude/rules/naming.md.',
            $this->find($this->layer(), fn (Node $node): bool => $node instanceof Node\Stmt\EnumCase
                && preg_match('/^[A-Z][A-Z0-9]*(_[A-Z0-9]+)*$/', $node->name->toString()) !== 1),
        );
    }

    public function test_enums_have_no_enum_suffix(): void
    {
        $this->assertMatchesBaseline(
            'enums.no-enum-suffix',
            'Name an enum by the value it holds, without an Enum suffix: DeploymentStatus. See .claude/rules/naming.md.',
            $this->namedWith($this->layer(), 'Enum'),
        );
    }

    public function test_enums_live_in_a_domain_enums_directory(): void
    {
        $this->assertMatchesBaseline(
            'enums.placement',
            'Put an enum in app/Domains/{Domain}/Enums. See .claude/rules/architecture.md.',
            $this->placedOutside(
                $this->layer()->leave(fn (ObjectDescription $object): bool => $object->type === ObjectType::_ENUM),
                '/^App\\\\Domains\\\\(\w+\\\\)+Enums\\\\\w+$/',
            ),
        );
    }
}
