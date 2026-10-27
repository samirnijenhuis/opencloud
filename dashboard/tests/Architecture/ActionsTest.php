<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Domains\Audit\Models\AuditEvent;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPUnit\Architecture\Elements\Layer\Layer;
use PHPUnit\Architecture\Elements\ObjectDescription;
use PHPUnit\Architecture\Enums\ObjectType;

final class ActionsTest extends ArchitectureTestCase
{
    public function test_actions_have_one_public_method(): void
    {
        $this->assertMatchesBaseline(
            'actions.single-public-method',
            'An Action has one public method, execute(). Move other public methods to a service class. See .claude/rules/actions-jobs.md.',
            $this->find($this->actions(), fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod
                && $node->isPublic()
                && ! in_array($node->name->toString(), ['__construct', 'execute'], true)),
        );
    }

    public function test_mutating_actions_record_an_audit_event(): void
    {
        $silent = $this->actions()->leave(fn (ObjectDescription $action): bool => $this->hasNode($action->path, fn (Node $node): bool => $this->isMutation($node) || $this->isJobDispatch($node))
            && ! $this->hasNode($action->path, fn (Node $node): bool => $this->isStaticCall($node, AuditEvent::class, 'record')));

        $this->assertMatchesBaseline(
            'actions.record-audit-event',
            'An Action that writes records or dispatches a job also calls AuditEvent::record(). See .claude/rules/actions-jobs.md.',
            $this->find($silent, fn (Node $node): bool => $node instanceof Node\Stmt\Class_),
        );
    }

    public function test_actions_take_only_dependencies_in_the_constructor(): void
    {
        $violations = [];

        foreach ($this->actions() as $action) {
            foreach ((new NodeFinder)->findInstanceOf($this->ast($action->path), Node\Stmt\ClassMethod::class) as $method) {
                foreach ($method->name->toString() === '__construct' ? $method->params : [] as $param) {
                    if (! $this->isObjectType($param->type) && ! $this->isContextualAttribute($param)) {
                        $violations[] = $this->relative($action->path).':'.$param->getStartLine();
                    }
                }
            }
        }

        $this->assertMatchesBaseline(
            'actions.no-scalar-constructor-arguments',
            'The constructor takes the dependencies; pass execution values as typed execute() parameters. See .claude/rules/actions-jobs.md.',
            $violations,
        );
    }

    public function test_only_actions_have_an_execute_method(): void
    {
        $others = $this->layer()->exclude(fn (ObjectDescription $object): bool => str_contains($object->name, '\\Actions\\'));

        $this->assertMatchesBaseline(
            'actions.execute-only-on-actions',
            'execute() means a mutation, so only Actions have it. Name the method after what it returns. See .claude/rules/actions-jobs.md.',
            $this->find($others, fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod
                && $node->isPublic()
                && $node->name->toString() === 'execute'),
        );
    }

    public function test_actions_have_no_action_suffix(): void
    {
        $this->assertMatchesBaseline(
            'actions.no-action-suffix',
            'Name an Action by its verb phrase, without an Action suffix: StartDeployment. See .claude/rules/naming.md.',
            $this->namedWith($this->classes('/\\\\Actions\\\\/'), 'Action'),
        );
    }

    public function test_actions_live_in_a_domain_actions_directory(): void
    {
        $this->assertMatchesBaseline(
            'actions.placement',
            'Put an Action in app/Domains/{Domain}/Actions. See .claude/rules/architecture.md.',
            $this->placedOutside(
                $this->classes('/\\\\Actions\\\\/'),
                '/^App\\\\Domains\\\\(\w+\\\\)+Actions\\\\/',
            ),
        );
    }

    private function actions(): Layer
    {
        return $this->classes('/^App\\\\Domains\\\\.+\\\\Actions\\\\/')->leave(
            fn (ObjectDescription $object): bool => $object->type === ObjectType::_CLASS
                && ! $object->reflectionClass->isAbstract(),
        );
    }

    private function isJobDispatch(Node $node): bool
    {
        return $this->isFunctionCall($node, 'dispatch', 'dispatch_sync')
            || ($node instanceof Node\Expr\StaticCall
                && $node->class instanceof Node\Name
                && str_contains($node->class->toString(), '\\Jobs\\')
                && $node->name instanceof Node\Identifier
                && str_starts_with($node->name->toString(), 'dispatch'));
    }

    private function isObjectType(?Node $type): bool
    {
        return match (true) {
            $type instanceof Node\Name => true,
            $type instanceof Node\NullableType => $this->isObjectType($type->type),
            $type instanceof Node\UnionType, $type instanceof Node\IntersectionType => array_filter(
                $type->types,
                fn (Node $member): bool => ! $this->isObjectType($member),
            ) === [],
            default => false,
        };
    }

    private function isContextualAttribute(Node\Param $param): bool
    {
        foreach ($param->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (str_starts_with($attribute->name->toString(), 'Illuminate\\Container\\Attributes\\')) {
                    return true;
                }
            }
        }

        return false;
    }
}
