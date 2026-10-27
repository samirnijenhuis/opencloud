<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Domains\Members\Enums\Role;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPUnit\Architecture\Elements\Layer\Layer;
use PHPUnit\Architecture\Elements\ObjectDescription;

final class PoliciesTest extends ArchitectureTestCase
{
    public function test_policies_are_registered_on_the_model(): void
    {
        $bound = [];

        foreach ($this->subclassesOf(Model::class) as $model) {
            foreach ($model->reflectionClass->getAttributes(UsePolicy::class) as $attribute) {
                $bound[] = $attribute->newInstance()->class;
            }
        }

        $this->assertMatchesBaseline(
            'policies.via-model-attribute',
            'Bind a policy with #[UsePolicy(XPolicy::class)] on its model, not by name guessing, Gate::policy() or a $policies array. See .claude/rules/registration-checklists.md.',
            [
                ...$this->find($this->layer(), fn (Node $node): bool => $this->isStaticCall($node, Gate::class, 'policy', 'guessPolicyNamesUsing')),
                ...$this->find($this->subclassesOf(ServiceProvider::class), fn (Node $node): bool => $node instanceof Node\PropertyItem
                    && $node->name->toString() === 'policies'),
                ...$this->find(
                    $this->policies()->exclude(fn (ObjectDescription $policy): bool => in_array($policy->name, $bound, true)),
                    fn (Node $node): bool => $node instanceof Node\Stmt\Class_,
                ),
            ],
        );
    }

    public function test_policies_end_with_policy(): void
    {
        $this->assertMatchesBaseline(
            'policies.policy-suffix',
            'End a policy name with Policy: ClusterPolicy. See .claude/rules/naming.md.',
            $this->namedWithout($this->classes('/\\\\Policies\\\\/'), 'Policy'),
        );
    }

    public function test_authorization_checks_an_ability_not_a_role(): void
    {
        $roleNameChecks = array_map(fn (Role $role): string => 'is'.Str::studly($role->value), Role::cases());

        $this->assertMatchesBaseline(
            'authorization.no-role-checks',
            'Ask $user->role->allows(Ability::X); never compare a Role case or call a role-name check. Role::allows() is the one map from roles to abilities. See .claude/rules/authorization.md.',
            $this->find(
                $this->layer()->exclude(fn (ObjectDescription $object): bool => $object->name === Role::class),
                fn (Node $node): bool => $this->isMethodCall($node, 'hasRole', 'hasAnyRole', 'hasAllRoles', 'hasExactRoles', ...$roleNameChecks)
                    || ($this->isComparison($node) && ($this->isRoleCase($node->left) || $this->isRoleCase($node->right)))
                    || ($this->isFunctionCall($node, 'in_array') && $this->hasRoleCase($node->getArgs()))
                    || ($node instanceof Node\MatchArm && $this->hasRoleCase($node->conds ?? [])),
            ),
        );
    }

    public function test_policies_live_in_a_domain_policies_directory(): void
    {
        $this->assertMatchesBaseline(
            'policies.placement',
            'Put a policy in app/Domains/{Domain}/Policies. See .claude/rules/architecture.md.',
            $this->placedOutside(
                $this->layer()->leave(fn (ObjectDescription $object): bool => str_ends_with($object->name, 'Policy')
                    && ! $object->reflectionClass->isAbstract()),
                '/^App\\\\Domains\\\\(\w+\\\\)+Policies\\\\\w+$/',
            ),
        );
    }

    private function policies(): Layer
    {
        return $this->classes('/\\\\Policies\\\\\w+Policy$/')->leave(fn (ObjectDescription $object): bool => ! $object->reflectionClass->isAbstract());
    }

    /** @phpstan-assert-if-true =Node\Expr\BinaryOp $node */
    private function isComparison(Node $node): bool
    {
        return $node instanceof Node\Expr\BinaryOp\Identical
            || $node instanceof Node\Expr\BinaryOp\NotIdentical
            || $node instanceof Node\Expr\BinaryOp\Equal
            || $node instanceof Node\Expr\BinaryOp\NotEqual;
    }

    /** @param array<Node> $nodes */
    private function hasRoleCase(array $nodes): bool
    {
        return (new NodeFinder)->findFirst($nodes, $this->isRoleCase(...)) !== null;
    }

    private function isRoleCase(Node $node): bool
    {
        return $node instanceof Node\Expr\ClassConstFetch
            && $node->class instanceof Node\Name
            && $node->class->toString() === Role::class
            && $node->name instanceof Node\Identifier
            && $node->name->toString() !== 'class';
    }
}
