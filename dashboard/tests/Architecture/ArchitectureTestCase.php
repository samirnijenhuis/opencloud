<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Closure;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Architecture\ArchitectureAsserts;
use PHPUnit\Architecture\Elements\Layer\Layer;
use PHPUnit\Architecture\Elements\ObjectDescription;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

abstract class ArchitectureTestCase extends TestCase
{
    use ArchitectureAsserts;

    private const string BASELINE = __DIR__.'/baseline.json';

    /** @var list<string> */
    protected array $excludedPaths = [
        'bootstrap', 'config', 'database', 'docker', 'node_modules', 'public', 'resources', 'routes', 'storage',
        'tests',
    ];

    /** @var array<string, list<Node>> */
    private static array $ast = [];

    protected function classes(string $namespaceRegex): Layer
    {
        return $this->layer()->leaveByNameRegex($namespaceRegex);
    }

    protected function subclassesOf(string $parent): Layer
    {
        return $this->layer()->leave(
            fn (ObjectDescription $object): bool => $object->reflectionClass->isSubclassOf($parent),
        );
    }

    /**
     * @param  iterable<ObjectDescription|string>  $sources
     * @return list<string>
     */
    protected function find(iterable $sources, Closure $match): array
    {
        $violations = [];

        foreach ($sources as $source) {
            $path = $source instanceof ObjectDescription ? $source->path : $source;

            foreach ((new NodeFinder)->find($this->ast($path), $match) as $node) {
                $violations[] = $this->relative($path).':'.$node->getStartLine();
            }
        }

        return $violations;
    }

    /**
     * @param  iterable<ObjectDescription>  $objects
     * @return list<string>
     */
    protected function namedWith(iterable $objects, string $suffix): array
    {
        return $this->find($objects, fn (Node $node): bool => $node instanceof Node\Stmt\ClassLike
            && $node->name !== null
            && str_ends_with($node->name->toString(), $suffix));
    }

    /**
     * @param  iterable<ObjectDescription>  $objects
     * @return list<string>
     */
    protected function namedWithout(iterable $objects, string $suffix): array
    {
        return $this->find($objects, fn (Node $node): bool => $node instanceof Node\Stmt\Class_
            && $node->name !== null
            && ! str_ends_with($node->name->toString(), $suffix));
    }

    /** @return list<string> */
    protected function placedOutside(Layer $objects, string $namespacePattern): array
    {
        return $this->find(
            $objects->exclude(fn (ObjectDescription $object): bool => preg_match($namespacePattern, $object->name) === 1),
            fn (Node $node): bool => $node instanceof Node\Stmt\ClassLike && $node->name !== null,
        );
    }

    /**
     * @param  iterable<ObjectDescription>  $objects
     * @param  list<string>  $exceptPrefixes
     * @return list<string>
     */
    protected function dependenciesOn(iterable $objects, string $namespacePrefix, array $exceptPrefixes = []): array
    {
        $violations = [];

        foreach ($objects as $object) {
            $seen = [];

            foreach ((new NodeFinder)->findInstanceOf($this->ast($object->path), Node\Name::class) as $name) {
                $class = $name->toString();

                if (str_starts_with($class, $namespacePrefix)
                    && ! isset($seen[$class])
                    && array_filter($exceptPrefixes, fn (string $prefix): bool => str_starts_with($class, $prefix)) === []) {
                    $seen[$class] = true;
                    $violations[] = $this->relative($object->path).':'.$name->getStartLine();
                }
            }
        }

        return $violations;
    }

    protected function hasNode(string $path, Closure $match): bool
    {
        return (new NodeFinder)->findFirst($this->ast($path), $match) !== null;
    }

    /** @return list<Node> */
    protected function ast(string $path): array
    {
        return self::$ast[$path] ??= (new NodeTraverser(new NameResolver))->traverse(
            (new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($path)) ?? [],
        );
    }

    /** @return list<string> */
    protected function phpFiles(string ...$directories): array
    {
        $files = Finder::create()->files()->name('*.php')->in(array_map($this->absolute(...), $directories));

        return array_values(array_map(fn ($file): string => (string) $file->getRealPath(), iterator_to_array($files)));
    }

    /** @phpstan-assert-if-true =Node\Expr\FuncCall $node */
    protected function isFunctionCall(Node $node, string ...$names): bool
    {
        return $node instanceof Node\Expr\FuncCall
            && $node->name instanceof Node\Name
            && in_array(strtolower($node->name->getLast()), $names, true);
    }

    /** @phpstan-assert-if-true =Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $node */
    protected function isMethodCall(Node $node, string ...$names): bool
    {
        return ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall)
            && $node->name instanceof Node\Identifier
            && in_array($node->name->toString(), $names, true);
    }

    protected function isStaticCall(Node $node, string $class, string ...$methods): bool
    {
        return $node instanceof Node\Expr\StaticCall
            && $node->class instanceof Node\Name
            && in_array($node->class->toString(), [$class, class_basename($class)], true)
            && ($methods === [] || ($node->name instanceof Node\Identifier && in_array($node->name->toString(), $methods, true)));
    }

    protected function isMutation(Node $node): bool
    {
        return $this->isMethodCall(
            $node, 'save', 'saveQuietly', 'update', 'updateQuietly', 'delete', 'deleteQuietly', 'forceDelete',
            'restore', 'attach', 'detach', 'sync', 'syncWithoutDetaching', 'toggle', 'increment', 'decrement',
            'create', 'createMany', 'createQuietly', 'forceCreate', 'firstOrCreate', 'updateOrCreate', 'insert',
            'upsert',
        ) || ($node instanceof Node\Expr\StaticCall
            && $node->class instanceof Node\Name
            && is_subclass_of($node->class->toString(), Model::class)
            && $node->name instanceof Node\Identifier
            && in_array($node->name->toString(), ['create', 'forceCreate', 'updateOrCreate', 'firstOrCreate', 'insert', 'upsert', 'destroy'], true));
    }

    /** @param list<string> $violations */
    protected function assertMatchesBaseline(string $rule, string $fix, array $violations): void
    {
        $counts = array_count_values(array_map(fn (string $violation): string => explode(':', $violation)[0], $violations));
        ksort($counts);

        if (getenv('ARCHITECTURE_BASELINE') === 'update') {
            $this->writeBaseline($rule, $counts);
            $this->addToAssertionCount(1);

            return;
        }

        $baseline = $this->readBaseline()[$rule] ?? [];
        $problems = [];

        foreach ($counts as $file => $count) {
            if ($count > ($baseline[$file] ?? 0)) {
                $lines = array_filter($violations, fn (string $violation): bool => str_starts_with($violation, $file.':'));
                $problems[] = 'New: '.implode(', ', $lines);
            }
        }

        foreach ($baseline as $file => $count) {
            if (($counts[$file] ?? 0) < $count) {
                $problems[] = "Fixed: {$file} now has ".($counts[$file] ?? 0)." of {$count}. Lower its count in tests/Architecture/baseline.json.";
            }
        }

        self::assertSame([], $problems, "{$fix}\n".implode("\n", $problems));
    }

    protected function relative(string $path): string
    {
        return substr($path, strlen($this->absolute('')));
    }

    protected function absolute(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }

    /** @return array<string, array<string, int>> */
    private function readBaseline(): array
    {
        return is_file(self::BASELINE) ? json_decode((string) file_get_contents(self::BASELINE), true) : [];
    }

    /** @param array<string, int> $counts */
    private function writeBaseline(string $rule, array $counts): void
    {
        $baseline = $this->readBaseline();
        $baseline[$rule] = $counts;
        $baseline = array_filter($baseline);
        ksort($baseline);

        file_put_contents(self::BASELINE, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
