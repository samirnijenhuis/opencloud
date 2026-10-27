<?php

declare(strict_types=1);

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

$dashboard = dirname(__DIR__, 2).'/dashboard';
require $dashboard.'/vendor/autoload.php';

[$script, $mapFile] = $argv + [null, null];
$moves = json_decode((string) file_get_contents($mapFile), true, flags: JSON_THROW_ON_ERROR);

$classes = [];
foreach ($moves as $oldPath => $newPath) {
    $classes[pathToClass($oldPath)] = pathToClass($newPath);
}

$scan = [];
foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'tests'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$dashboard}/{$directory}", FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with((string) $file, '.php') && ! str_contains((string) $file, '/tests/Fixtures/') && ! str_contains((string) $file, '/bootstrap/cache/')) {
            $scan[] = substr((string) $file, strlen($dashboard) + 1);
        }
    }
}

$problems = [];
$rewritten = [];
foreach ($scan as $path) {
    $source = (string) file_get_contents("{$dashboard}/{$path}");
    $result = rewrite($source, $path, $moves[$path] ?? null, $classes, $problems);
    if ($result !== $source) {
        $rewritten[$path] = $result;
    }
}

if ($problems !== []) {
    fwrite(STDERR, implode("\n", array_unique($problems))."\n");
    exit(1);
}

foreach ($rewritten as $path => $result) {
    file_put_contents("{$dashboard}/{$path}", $result);
}
$changed = count($rewritten);

foreach ($moves as $oldPath => $newPath) {
    @mkdir(dirname("{$dashboard}/{$newPath}"), 0777, true);
    passthru('git -C '.escapeshellarg($dashboard).' mv '.escapeshellarg($oldPath).' '.escapeshellarg($newPath), $status);
    if ($status !== 0) {
        exit($status);
    }
}

foreach (array_unique(array_map(fn (string $old): string => dirname("{$dashboard}/{$old}"), array_keys($moves))) as $directory) {
    while (is_dir($directory) && count(scandir($directory)) === 2) {
        rmdir($directory);
        $directory = dirname($directory);
    }
}

echo "rewrote {$changed} files, moved ".count($moves)." files\n";

function pathToClass(string $path): string
{
    $path = preg_replace('/\.php$/', '', $path);
    $path = preg_replace('#^app/#', 'App/', $path);
    $path = preg_replace('#^tests/#', 'Tests/', $path);

    return str_replace('/', '\\', $path);
}

function namespaceOf(string $class): string
{
    return substr($class, 0, (int) strrpos($class, '\\'));
}

function shortName(string $class): string
{
    return substr($class, (int) strrpos($class, '\\') + 1);
}

/**
 * @param  array<string, string>  $classes
 * @param  list<string>  $problems
 */
function rewrite(string $source, string $path, ?string $newPath, array $classes, array &$problems): string
{
    $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
    $traverser = new NodeTraverser(new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => false]));
    $ast = $traverser->traverse($ast);
    $finder = new NodeFinder;

    $namespace = $finder->findFirstInstanceOf($ast, Node\Stmt\Namespace_::class);
    $oldNamespace = $namespace?->name?->toString() ?? '';
    $newNamespace = $newPath === null ? $oldNamespace : namespaceOf(pathToClass($newPath));

    $edits = [];
    $imports = [];
    $importedShort = [];
    $useNodes = [];

    foreach ($finder->findInstanceOf($ast, Node\Stmt\GroupUse::class) as $group) {
        $problems[] = "{$path}: group use statement, rewrite by hand";
    }

    foreach ($finder->findInstanceOf($ast, Node\Stmt\Use_::class) as $use) {
        if ($use->type !== Node\Stmt\Use_::TYPE_NORMAL) {
            continue;
        }
        $useNodes[] = $use;
        foreach ($use->uses as $item) {
            $full = $item->name->toString();
            $alias = $item->getAlias()->toString();
            $importedShort[$alias] = $classes[$full] ?? $full;
            if (isset($classes[$full])) {
                $edits[] = [$item->name->getStartFilePos(), $item->name->getEndFilePos(), $classes[$full]];
            }
        }
    }

    if ($namespace !== null && $newNamespace !== $oldNamespace) {
        $edits[] = [$namespace->name->getStartFilePos(), $namespace->name->getEndFilePos(), $newNamespace];
    }

    $declared = [];
    foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $classLike) {
        if ($classLike->name !== null) {
            $declared[] = $classLike->name->toString();
        }
    }

    $useItemNames = [];
    foreach ($useNodes as $use) {
        foreach ($use->uses as $item) {
            $useItemNames[spl_object_id($item->name)] = true;
        }
    }

    foreach ($finder->findInstanceOf($ast, Node\Name::class) as $name) {
        if (isset($useItemNames[spl_object_id($name)]) || ($namespace !== null && $name === $namespace->name)) {
            continue;
        }
        $resolved = $name->getAttribute('resolvedName');
        if (! $resolved instanceof Node\Name) {
            continue;
        }
        $full = $resolved->toString();
        $original = $name->getAttribute('originalName', $name);

        if (isset($classes[$full])) {
            if ($original instanceof Node\Name\FullyQualified || $original->isQualified()) {
                $edits[] = [$name->getStartFilePos(), $name->getEndFilePos(), '\\'.$classes[$full]];
            } elseif (! isset($importedShort[$original->toString()])) {
                $imports[$classes[$full]] = true;
            }

            continue;
        }

        if ($newPath !== null && $original->isUnqualified() && $oldNamespace !== '' && namespaceOf($full) === $oldNamespace
            && ! isset($importedShort[$original->toString()]) && (class_exists($full) || interface_exists($full) || trait_exists($full) || enum_exists($full))) {
            $problems[] = "{$path}: {$original} resolves to unmoved {$full} in the old namespace";
        }
    }

    foreach ($finder->findInstanceOf($ast, Node\Scalar\String_::class) as $string) {
        $value = ltrim($string->value, '\\');
        if (isset($classes[$value])) {
            $quote = $source[$string->getStartFilePos()];
            $replacement = $quote === '"' ? str_replace('\\', '\\\\', $classes[$value]) : $classes[$value];
            $edits[] = [$string->getStartFilePos(), $string->getEndFilePos(), $quote.$replacement.$quote];
        } elseif (array_filter(array_unique(array_map('namespaceOf', array_keys($classes))), fn (string $old): bool => str_starts_with($value, $old.'\\')) !== []) {
            $problems[] = "{$path}:{$string->getStartLine()}: string {$string->value} looks like a class name";
        }
    }

    $seenComments = [];
    foreach ($finder->find($ast, fn (Node $node): bool => $node->getComments() !== []) as $node) {
        foreach ($node->getComments() as $comment) {
            if (isset($seenComments[$comment->getStartFilePos()])) {
                continue;
            }
            $seenComments[$comment->getStartFilePos()] = true;
            commentEdits($comment, $oldNamespace, $classes, $importedShort, $edits, $imports);
        }
    }

    foreach (array_keys($imports) as $class) {
        $short = shortName($class);
        if (namespaceOf($class) === $newNamespace && ! isset($importedShort[$short])) {
            unset($imports[$class]);

            continue;
        }
        if ((isset($importedShort[$short]) && $importedShort[$short] !== $class) || in_array($short, $declared, true)) {
            $problems[] = "{$path}: import {$class} collides with {$short}";
        }
    }
    $shorts = array_map('shortName', array_keys($imports));
    if (count($shorts) !== count(array_unique($shorts))) {
        $problems[] = "{$path}: two new imports share a short name";
    }

    $edits = array_values(array_unique($edits, SORT_REGULAR));
    usort($edits, fn (array $a, array $b): int => $b[0] <=> $a[0]);
    $last = PHP_INT_MAX;
    foreach ($edits as [$start, $end, $text]) {
        if ($end >= $last) {
            $problems[] = "{$path}: overlapping edits at byte {$start}";

            continue;
        }
        $source = substr($source, 0, $start).$text.substr($source, $end + 1);
        $last = $start;
    }

    if ($imports !== []) {
        $lines = implode('', array_map(fn (string $class): string => "use {$class};\n", array_keys($imports)));
        $source = insertImports($source, $lines, $path, $problems);
    }

    return $source;
}

/**
 * @param  array<string, string>  $classes
 * @param  array<string, string>  $importedShort
 * @param  list<array{int, int, string}>  $edits
 * @param  array<string, bool>  $imports
 */
function commentEdits(Comment $comment, string $oldNamespace, array $classes, array $importedShort, array &$edits, array &$imports): void
{
    $text = $comment->getText();
    $offset = $comment->getStartFilePos();

    preg_match_all('/\\\\?App(?:\\\\[A-Z][A-Za-z0-9]*)+/', $text, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[0] as [$match, $position]) {
        $full = ltrim($match, '\\');
        if (isset($classes[$full])) {
            $prefix = str_starts_with($match, '\\') ? '\\' : '';
            $edits[] = [$offset + $position, $offset + $position + strlen($match) - 1, $prefix.$classes[$full]];
        }
    }

    if ($oldNamespace === '' || ! str_starts_with($text, '/**')) {
        return;
    }
    preg_match_all('/(?<![\\\\\w$])([A-Z][A-Za-z0-9]*)\b/', $text, $words);
    foreach (array_unique($words[1]) as $word) {
        $full = "{$oldNamespace}\\{$word}";
        if (isset($classes[$full]) && ! isset($importedShort[$word])) {
            $imports[$classes[$full]] = true;
        }
    }
}

/** @param list<string> $problems */
function insertImports(string $source, string $lines, string $path, array &$problems): string
{
    if (preg_match_all('/^use [^;(]+;\n/m', $source, $matches, PREG_OFFSET_CAPTURE) && $matches[0] !== []) {
        [$text, $position] = end($matches[0]);

        return substr($source, 0, $position + strlen($text)).$lines.substr($source, $position + strlen($text));
    }
    if (preg_match('/^namespace [^;]+;\n/m', $source, $match, PREG_OFFSET_CAPTURE)) {
        $position = $match[0][1] + strlen($match[0][0]);

        return substr($source, 0, $position)."\n".$lines.substr($source, $position);
    }
    $problems[] = "{$path}: no namespace and no use statement to put new imports after";

    return $source;
}
