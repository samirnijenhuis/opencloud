<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

$dashboard = dirname(__DIR__, 2).'/dashboard';
require $dashboard.'/vendor/autoload.php';

$actions = [];
foreach (glob("{$dashboard}/app/Domains/*/Actions/*.php") as $path) {
    $actions['App\\'.str_replace('/', '\\', substr($path, strlen("{$dashboard}/app/"), -4))] = true;
}

$files = [];
foreach (['app', 'database', 'routes', 'tests'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$dashboard}/{$directory}", FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with((string) $file, '.php') && ! str_contains((string) $file, '/tests/Fixtures/')) {
            $files[] = (string) $file;
        }
    }
}

$finder = new NodeFinder;
$rewritten = [];
$unresolved = [];
$renamedCalls = 0;
$renamedMocks = 0;

foreach ($files as $path) {
    $source = (string) file_get_contents($path);
    $ast = (new NodeTraverser(new NameResolver(null, ['replaceNodes' => false]), new ParentConnectingVisitor))
        ->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? []);
    $edits = [];

    foreach ($finder->findInstanceOf($ast, Node\Stmt\Class_::class) as $class) {
        $name = $class->namespacedName?->toString();
        if ($name === null || ! isset($actions[$name])) {
            continue;
        }
        $public = array_values(array_filter($class->getMethods(), fn (Node\Stmt\ClassMethod $method): bool => $method->isPublic() && $method->name->toString() !== '__construct'));
        if (count($public) !== 1 || $public[0]->name->toString() !== 'handle') {
            fwrite(STDERR, "{$name}: expected one public handle(), found ".implode(', ', array_map(fn ($m) => $m->name->toString(), $public))."\n");
            exit(1);
        }
        $edits[] = [$public[0]->name->getStartFilePos(), $public[0]->name->getEndFilePos(), 'execute'];
    }

    foreach ($finder->find($ast, fn (Node $node): bool => ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall)
        && $node->name instanceof Node\Identifier && $node->name->toString() === 'handle') as $call) {
        $type = typeOf($call->var, $finder);
        if ($type === null) {
            $unresolved[] = relative($path, $dashboard).':'.$call->getStartLine().'  '.trim(substr($source, $call->getStartFilePos(), min(90, $call->getEndFilePos() - $call->getStartFilePos() + 1)));

            continue;
        }
        if (isset($actions[$type])) {
            $edits[] = [$call->name->getStartFilePos(), $call->name->getEndFilePos(), 'execute'];
            $renamedCalls++;
        }
    }

    foreach ($finder->find($ast, fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
        && $node->name instanceof Node\Identifier
        && in_array($node->name->toString(), ['shouldReceive', 'shouldNotReceive', 'expects', 'allows', 'shouldHaveReceived', 'shouldNotHaveReceived'], true)
        && isset($node->args[0]) && $node->args[0] instanceof Node\Arg
        && $node->args[0]->value instanceof Node\Scalar\String_ && $node->args[0]->value->value === 'handle') as $expectation) {
        $type = mockedClass($expectation->var, $finder);
        if ($type === null) {
            $unresolved[] = relative($path, $dashboard).':'.$expectation->getStartLine().'  mock expectation on an unknown class';

            continue;
        }
        if (isset($actions[$type])) {
            $string = $expectation->args[0]->value;
            $edits[] = [$string->getStartFilePos(), $string->getEndFilePos(), "'execute'"];
            $renamedMocks++;
        }
    }

    if ($edits === []) {
        continue;
    }
    usort($edits, fn (array $a, array $b): int => $b[0] <=> $a[0]);
    foreach ($edits as [$start, $end, $text]) {
        $source = substr($source, 0, $start).$text.substr($source, $end + 1);
    }
    $rewritten[$path] = $source;
}

if ($unresolved !== []) {
    fwrite(STDERR, "Unresolved handle() receivers:\n".implode("\n", $unresolved)."\n");
    if (! in_array('--force', $argv, true)) {
        exit(1);
    }
}

foreach ($rewritten as $path => $source) {
    file_put_contents($path, $source);
}
echo 'renamed '.count($actions)." action methods, {$renamedCalls} calls, {$renamedMocks} mock expectations in ".count($rewritten)." files\n";

function relative(string $path, string $root): string
{
    return substr($path, strlen($root) + 1);
}

function classArgument(Node\Expr\CallLike $call): ?string
{
    $argument = $call->getArgs()[0]->value ?? null;
    if ($argument instanceof Node\Expr\ClassConstFetch && $argument->class instanceof Node\Name && $argument->name instanceof Node\Identifier && $argument->name->toString() === 'class') {
        return $argument->class->getAttribute('resolvedName')?->toString() ?? $argument->class->toString();
    }

    return null;
}

function nameOf(Node\Name $name): string
{
    return $name->getAttribute('resolvedName')?->toString() ?? $name->toString();
}

function typeOf(Node\Expr $expr, NodeFinder $finder): ?string
{
    if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
        return nameOf($expr->class);
    }
    if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name && in_array($expr->name->toString(), ['app', 'resolve'], true)) {
        return classArgument($expr);
    }
    if ($expr instanceof Node\Expr\MethodCall && $expr->name instanceof Node\Identifier && in_array($expr->name->toString(), ['make', 'makeWith'], true)) {
        return classArgument($expr);
    }
    if ($expr instanceof Node\Expr\MethodCall && $expr->name instanceof Node\Identifier && in_array($expr->name->toString(), ['sole', 'first', 'last'], true)) {
        return pushedClass($expr->var);
    }
    if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
        return variableType($expr, $finder);
    }
    if ($expr instanceof Node\Expr\PropertyFetch && $expr->var instanceof Node\Expr\Variable && $expr->var->name === 'this' && $expr->name instanceof Node\Identifier) {
        return propertyType($expr, $expr->name->toString(), $finder);
    }

    return null;
}

function pushedClass(Node\Expr $expr): ?string
{
    while ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\StaticCall) {
        if ($expr->name instanceof Node\Identifier && in_array($expr->name->toString(), ['pushed', 'dispatched', 'pushedJobs'], true)) {
            return classArgument($expr);
        }
        if ($expr instanceof Node\Expr\StaticCall) {
            return null;
        }
        $expr = $expr->var;
    }

    return null;
}

function enclosing(Node $node, string ...$types): ?Node
{
    $parent = $node->getAttribute('parent');
    while ($parent !== null) {
        foreach ($types as $type) {
            if ($parent instanceof $type) {
                return $parent;
            }
        }
        $parent = $parent->getAttribute('parent');
    }

    return null;
}

function variableType(Node\Expr\Variable $variable, NodeFinder $finder): ?string
{
    $scope = enclosing($variable, Node\Expr\ArrowFunction::class, Node\Expr\Closure::class, Node\Stmt\ClassMethod::class, Node\Stmt\Function_::class);
    while ($scope !== null) {
        foreach ($scope->getParams() as $param) {
            if ($param->var instanceof Node\Expr\Variable && $param->var->name === $variable->name && $param->type instanceof Node\Name) {
                return nameOf($param->type);
            }
        }
        if (! $scope instanceof Node\Expr\ArrowFunction) {
            foreach ($finder->findInstanceOf($scope->getStmts() ?? [], Node\Expr\Assign::class) as $assign) {
                if ($assign->var instanceof Node\Expr\Variable && $assign->var->name === $variable->name && $assign->getStartFilePos() < $variable->getStartFilePos()) {
                    $type = typeOf($assign->expr, $finder);
                    if ($type !== null) {
                        return $type;
                    }
                }
            }
            if ($scope instanceof Node\Expr\Closure) {
                $inherited = array_filter($scope->uses, fn (Node\ClosureUse $use): bool => $use->var->name === $variable->name);
                if ($inherited === []) {
                    return null;
                }
            } else {
                return null;
            }
        }
        $scope = enclosing($scope, Node\Expr\ArrowFunction::class, Node\Expr\Closure::class, Node\Stmt\ClassMethod::class, Node\Stmt\Function_::class);
    }

    return null;
}

function propertyType(Node $node, string $property, NodeFinder $finder): ?string
{
    $class = enclosing($node, Node\Stmt\Class_::class);
    if ($class === null) {
        return null;
    }
    foreach ($class->getProperties() as $declaration) {
        foreach ($declaration->props as $prop) {
            if ($prop->name->toString() === $property && $declaration->type instanceof Node\Name) {
                return nameOf($declaration->type);
            }
        }
    }
    $constructor = $class->getMethod('__construct');
    foreach ($constructor?->getParams() ?? [] as $param) {
        if ($param->flags !== 0 && $param->var instanceof Node\Expr\Variable && $param->var->name === $property && $param->type instanceof Node\Name) {
            return nameOf($param->type);
        }
    }

    return null;
}

function mockedClass(Node\Expr $expr, NodeFinder $finder): ?string
{
    while ($expr instanceof Node\Expr\MethodCall) {
        if ($expr->name instanceof Node\Identifier && in_array($expr->name->toString(), ['mock', 'partialMock', 'spy'], true)) {
            return classArgument($expr);
        }
        $expr = $expr->var;
    }
    if ($expr instanceof Node\Expr\StaticCall && $expr->name instanceof Node\Identifier && in_array($expr->name->toString(), ['mock', 'spy'], true)) {
        return classArgument($expr);
    }
    if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
        $closure = enclosing($expr, Node\Expr\Closure::class, Node\Expr\ArrowFunction::class);
        $call = $closure?->getAttribute('parent')?->getAttribute('parent');
        if ($call instanceof Node\Expr\MethodCall && $call->name instanceof Node\Identifier && in_array($call->name->toString(), ['mock', 'partialMock', 'spy'], true)) {
            return classArgument($call);
        }
        $scope = enclosing($expr, Node\Stmt\ClassMethod::class, Node\Stmt\Function_::class);
        foreach ($finder->findInstanceOf($scope?->getStmts() ?? [], Node\Expr\Assign::class) as $assign) {
            if ($assign->var instanceof Node\Expr\Variable && $assign->var->name === $expr->name && $assign->expr instanceof Node\Expr\CallLike) {
                return mockedClass($assign->expr, $finder);
            }
        }
    }

    return null;
}
