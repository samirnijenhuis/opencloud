<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPUnit\Architecture\Elements\ObjectDescription;
use PHPUnit\Architecture\Enums\ObjectType;

final class TraitsTest extends ArchitectureTestCase
{
    public function test_traits_have_two_consumers(): void
    {
        $consumers = [];

        foreach ($this->layer() as $object) {
            foreach ((new NodeFinder)->findInstanceOf($this->ast($object->path), Node\Stmt\TraitUse::class) as $use) {
                foreach ($use->traits as $trait) {
                    $consumers[$trait->toString()][$object->name] = true;
                }
            }
        }

        $this->assertMatchesBaseline(
            'traits.two-consumers',
            'A trait with one consumer is inlined into that class. See .claude/rules/architecture.md.',
            $this->find(
                $this->layer()->leave(fn (ObjectDescription $object): bool => $object->type === ObjectType::_TRAIT
                    && count($consumers[$object->name] ?? []) < 2),
                fn (Node $node): bool => $node instanceof Node\Stmt\Trait_,
            ),
        );
    }
}
