<?php

$dashboard = dirname(__DIR__, 2).'/dashboard';
$index = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$dashboard}/app", FilesystemIterator::SKIP_DOTS)) as $file) {
    $relative = substr((string) $file, strlen($dashboard) + 1);
    $index[basename($relative, '.php')][] = dirname($relative);
}
$fallback = require __DIR__.'/test-fallback.php';
$moves = [];
$unmatched = [];
foreach (['tests/Feature', 'tests/Unit'] as $root) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$dashboard}/{$root}", FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = substr((string) $file, strlen($dashboard) + 1);
        $name = basename($relative, '.php');
        $subject = preg_replace('/Test$/', '', $name);
        if (isset($fallback[$relative])) {
            $directory = $fallback[$relative];
        } elseif (count($index[$subject] ?? []) === 1) {
            $directory = $index[$subject][0];
        } else {
            $unmatched[] = $relative.(isset($index[$subject]) ? ' (ambiguous)' : '');
            continue;
        }
        $moves[$relative] = preg_replace("#^app/#", "tests/", $directory)."/{$name}.php";
    }
}
if ($unmatched !== []) {
    fwrite(STDERR, implode("\n", $unmatched)."\n");
    exit(1);
}
$duplicates = array_keys(array_filter(array_count_values($moves), fn (int $count): bool => $count > 1));
if ($duplicates !== []) {
    fwrite(STDERR, "Duplicate targets:\n".implode("\n", $duplicates)."\n");
    exit(1);
}
ksort($moves);
file_put_contents(__DIR__.'/test-moves.json', json_encode($moves, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo count($moves)." test files mapped\n";
