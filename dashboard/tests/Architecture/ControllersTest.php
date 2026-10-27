<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use PhpParser\Node;
use PHPUnit\Architecture\Elements\Layer\Layer;
use PHPUnit\Architecture\Elements\ObjectDescription;

final class ControllersTest extends ArchitectureTestCase
{
    public function test_controllers_do_not_read_the_user_from_the_auth_facade(): void
    {
        $this->assertMatchesBaseline(
            'controllers.no-auth-facade',
            'Read the user with $request->user(), not Auth:: or auth(). See .claude/rules/authorization.md.',
            $this->find($this->controllers(), fn (Node $node): bool => $this->isStaticCall($node, Auth::class, 'user', 'id', 'check', 'guest')
                || ($this->isMethodCall($node, 'user', 'id', 'check', 'guest')
                    && ($this->isFunctionCall($node->var, 'auth') || $this->isStaticCall($node->var, Auth::class, 'guard')))),
        );
    }

    public function test_controllers_do_not_abort(): void
    {
        $this->assertMatchesBaseline(
            'controllers.no-abort',
            'Check access in the Form Request and the Policy; let route model binding handle a missing record. See .claude/rules/authorization.md.',
            $this->find($this->controllers(), fn (Node $node): bool => $this->isFunctionCall($node, 'abort', 'abort_if', 'abort_unless')),
        );
    }

    public function test_controllers_do_not_mutate_models(): void
    {
        $this->assertMatchesBaseline(
            'controllers.no-mutations',
            'Controllers call an Action for every mutation. See .claude/rules/actions-jobs.md.',
            $this->find($this->controllers(), $this->isMutation(...)),
        );
    }

    public function test_controllers_receive_actions_as_method_parameters(): void
    {
        $this->assertMatchesBaseline(
            'controllers.actions-in-constructor',
            'Inject an Action as a parameter of the controller method that uses it, not the constructor. See .claude/rules/actions-jobs.md.',
            $this->find($this->controllers(), fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod
                && $node->name->toString() === '__construct'
                && array_filter($node->params, fn (Node\Param $param): bool => $param->type instanceof Node\Name
                    && str_contains($param->type->toString(), '\\Actions\\')) !== []),
        );
    }

    public function test_controller_mutations_go_through_form_requests(): void
    {
        $this->assertMatchesBaseline(
            'controllers.mutations-use-form-requests',
            'A controller method that calls an Action also takes a Form Request: Form Request, Policy, Action. See .claude/rules/authorization.md.',
            $this->find($this->controllers(), fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod
                && $node->isPublic()
                && $node->name->toString() !== '__construct'
                && $this->hasParameter($node, fn (string $class): bool => str_contains($class, '\\Actions\\'))
                && ! $this->hasParameter($node, fn (string $class): bool => is_subclass_of($class, FormRequest::class))),
        );
    }

    public function test_controllers_do_not_validate_inline(): void
    {
        $this->assertMatchesBaseline(
            'controllers.no-inline-validation',
            'Validate in a Form Request, not in the controller. See .claude/rules/architecture.md.',
            $this->find($this->controllers(), fn (Node $node): bool => $this->isMethodCall($node, 'validate', 'validateWithBag')
                || $this->isStaticCall($node, Validator::class)
                || $this->isFunctionCall($node, 'validator')),
        );
    }

    public function test_controllers_end_with_controller(): void
    {
        $this->assertMatchesBaseline(
            'controllers.controller-suffix',
            'End a controller name with Controller: ClusterController. See .claude/rules/naming.md.',
            $this->namedWithout($this->controllers(), 'Controller'),
        );
    }

    public function test_controllers_live_in_the_http_controllers_directory(): void
    {
        $this->assertMatchesBaseline(
            'controllers.placement',
            'Put a controller in app/Http/Controllers. See .claude/rules/architecture.md.',
            $this->placedOutside(
                $this->layer()->leave(fn (ObjectDescription $object): bool => str_ends_with($object->name, 'Controller')),
                '/^App\\\\Http\\\\Controllers\\\\/',
            ),
        );
    }

    private function hasParameter(Node\Stmt\ClassMethod $method, Closure $matchesClass): bool
    {
        return array_filter($method->params, fn (Node\Param $param): bool => $param->type instanceof Node\Name
            && $matchesClass($param->type->toString())) !== [];
    }

    private function controllers(): Layer
    {
        return $this->classes('/^App\\\\Http\\\\Controllers\\\\/');
    }
}
