<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

$root = dirname(__DIR__, 2);
$dashboard = "{$root}/dashboard";
require "{$dashboard}/vendor/autoload.php";

function upper(string $name): string
{
    return strtoupper($name) === $name ? $name : strtoupper((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $name));
}

function parse(string $source): array
{
    return (new NodeTraverser(new NameResolver(null, ['replaceNodes' => false]), new ParentConnectingVisitor))
        ->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? []);
}

function phpFiles(string $directory): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with((string) $file, '.php') && ! str_contains((string) $file, '/tests/Fixtures/')) {
            $files[] = (string) $file;
        }
    }

    return $files;
}

$finder = new NodeFinder;
$enums = [];
foreach (phpFiles("{$dashboard}/app") as $path) {
    foreach ($finder->findInstanceOf(parse((string) file_get_contents($path)), Node\Stmt\Enum_::class) as $enum) {
        foreach ($enum->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\EnumCase) {
                $enums[$enum->namespacedName->toString()][$stmt->name->toString()] = upper($stmt->name->toString());
            }
        }
    }
}

$short = [];
foreach ($enums as $enum => $cases) {
    $name = substr($enum, (int) strrpos($enum, '\\') + 1);
    if (isset($short[$name])) {
        fwrite(STDERR, "two enums named {$name}\n");
        exit(1);
    }
    $short[$name] = $cases;
}

$files = [];
foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'tests'] as $directory) {
    $files = [...$files, ...phpFiles("{$dashboard}/{$directory}")];
}

$rewritten = [];
$count = 0;
foreach ($files as $path) {
    $source = (string) file_get_contents($path);
    $ast = parse($source);
    $edits = [];

    foreach ($finder->findInstanceOf($ast, Node\Stmt\Enum_::class) as $enum) {
        foreach ($enum->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\EnumCase && $stmt->name->toString() !== upper($stmt->name->toString())) {
                $edits[] = [$stmt->name->getStartFilePos(), $stmt->name->getEndFilePos(), upper($stmt->name->toString())];
            }
        }
    }

    foreach ($finder->findInstanceOf($ast, Node\Expr\ClassConstFetch::class) as $fetch) {
        if (! $fetch->class instanceof Node\Name || ! $fetch->name instanceof Node\Identifier) {
            continue;
        }
        $class = strtolower($fetch->class->toString());
        if (in_array($class, ['self', 'static'], true)) {
            $parent = $fetch->getAttribute('parent');
            while ($parent !== null && ! $parent instanceof Node\Stmt\ClassLike) {
                $parent = $parent->getAttribute('parent');
            }
            $enum = $parent?->namespacedName?->toString();
        } else {
            $enum = $fetch->class->getAttribute('resolvedName')?->toString();
        }
        $case = $fetch->name->toString();
        if ($enum !== null && isset($enums[$enum][$case]) && $enums[$enum][$case] !== $case) {
            $edits[] = [$fetch->name->getStartFilePos(), $fetch->name->getEndFilePos(), $enums[$enum][$case]];
        }
    }

    $seen = [];
    foreach ($finder->find($ast, fn (Node $node): bool => $node->getComments() !== []) as $node) {
        foreach ($node->getComments() as $comment) {
            if (isset($seen[$comment->getStartFilePos()])) {
                continue;
            }
            $seen[$comment->getStartFilePos()] = true;
            foreach (commentEdits($comment->getText(), $short) as [$offset, $length, $text]) {
                $edits[] = [$comment->getStartFilePos() + $offset, $comment->getStartFilePos() + $offset + $length - 1, $text];
            }
        }
    }

    if ($edits === []) {
        continue;
    }
    $edits = array_values(array_unique($edits, SORT_REGULAR));
    usort($edits, fn (array $a, array $b): int => $b[0] <=> $a[0]);
    $last = PHP_INT_MAX;
    foreach ($edits as [$start, $end, $text]) {
        if ($end >= $last) {
            fwrite(STDERR, "{$path}: overlapping edits at byte {$start}\n");
            exit(1);
        }
        $source = substr($source, 0, $start).$text.substr($source, $end + 1);
        $last = $start;
        $count++;
    }
    $rewritten[$path] = $source;
}

foreach ($rewritten as $path => $source) {
    file_put_contents($path, $source);
}

foreach (array_slice($argv, 1) as $path) {
    $text = (string) file_get_contents($path);
    $edits = commentEdits($text, $short);
    usort($edits, fn (array $a, array $b): int => $b[0] <=> $a[0]);
    foreach ($edits as [$offset, $length, $replacement]) {
        $text = substr($text, 0, $offset).$replacement.substr($text, $offset + $length);
    }
    file_put_contents($path, $text);
    $count += count($edits);
}

echo 'renamed '.array_sum(array_map('count', $enums))." cases in ".count($enums)." enums, {$count} edits in ".count($rewritten)." PHP files\n";

/** @return list<array{int, int, string}> */
function commentEdits(string $text, array $short): array
{
    $edits = [];
    preg_match_all('/(?<![A-Za-z0-9_\\\\])([A-Z][A-Za-z0-9]*)::([A-Z][A-Za-z0-9]*)\b/', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($matches as $match) {
        [$enum, $case] = [$match[1][0], $match[2][0]];
        if (isset($short[$enum][$case]) && $short[$enum][$case] !== $case) {
            $edits[] = [$match[2][1], strlen($case), $short[$enum][$case]];
        }
    }

    return $edits;
}
