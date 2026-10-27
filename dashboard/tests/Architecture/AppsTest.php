<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use PhpParser\Node;
use PHPUnit\Architecture\Elements\Layer\Layer;

final class AppsTest extends ArchitectureTestCase
{
    public function test_entry_points_do_not_return_a_success_flag(): void
    {
        $this->assertMatchesBaseline(
            'apps.no-success-flag',
            'The status code carries success; do not add a success key. See .claude/rules/architecture.md.',
            $this->find($this->entryPoints(), fn (Node $node): bool => $node instanceof Node\ArrayItem
                && $node->key instanceof Node\Scalar\String_
                && $node->key->value === 'success'),
        );
    }

    public function test_entry_points_do_not_branch_on_the_request_method(): void
    {
        $this->assertMatchesBaseline(
            'apps.no-request-method-branching',
            'Give each behaviour its own route, Form Request and controller method. See .claude/rules/authorization.md.',
            $this->find($this->entryPoints(), fn (Node $node): bool => $this->isMethodCall($node, 'isMethod')),
        );
    }

    public function test_exception_handling_does_not_check_request_paths(): void
    {
        $this->assertMatchesBaseline(
            'apps.no-path-checks-in-exception-handling',
            'Pick the response format with expectsJson() or a route middleware group, not with path checks in exception handling. See .claude/rules/architecture.md.',
            $this->find(
                [$this->absolute('bootstrap/app.php'), ...$this->classes('/\\\\Exceptions\\\\/')],
                fn (Node $node): bool => $this->isMethodCall($node, 'is', 'routeIs', 'fullUrlIs'),
            ),
        );
    }

    public function test_commands_are_referenced_by_class(): void
    {
        $this->assertMatchesBaseline(
            'console.commands-by-class',
            'Reference a console command by its class (CopyDatabase::class), never by its signature string. See .claude/rules/architecture.md.',
            [
                ...$this->find(
                    [$this->absolute('routes/console.php'), ...$this->layer()],
                    fn (Node $node): bool => ($this->isStaticCall($node, Artisan::class, 'call', 'queue')
                        || $this->isStaticCall($node, Schedule::class, 'command')
                        || $this->isMethodCall($node, 'command'))
                        && $this->hasStringFirstArgument($node),
                ),
                ...$this->find(
                    $this->subclassesOf(Command::class),
                    fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
                        && $this->isMethodCall($node, 'call', 'callSilent', 'callSilently')
                        && $node->var instanceof Node\Expr\Variable
                        && $node->var->name === 'this'
                        && $this->hasStringFirstArgument($node),
                ),
            ],
        );
    }

    private function hasStringFirstArgument(Node $call): bool
    {
        $arguments = $call instanceof Node\Expr\CallLike ? $call->getRawArgs() : [];

        return isset($arguments[0])
            && $arguments[0] instanceof Node\Arg
            && ($arguments[0]->value instanceof Node\Scalar\String_ || $arguments[0]->value instanceof Node\Scalar\InterpolatedString);
    }

    private function entryPoints(): Layer
    {
        return $this->classes('/^App\\\\(Http|Console)\\\\/');
    }
}
