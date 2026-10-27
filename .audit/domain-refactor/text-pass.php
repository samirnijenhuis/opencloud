<?php

$moves = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$pairs = [];
foreach ($moves as $old => $new) {
    $pairs[$old] = $new;
    $toClass = fn (string $path): string => str_replace(['app/', 'tests/', '/', '.php'], ['App/', 'Tests/', '\\', ''], $path);
    $pairs[$toClass($old)] = $toClass($new);
    $pairs[str_replace('\\', '\\\\', $toClass($old))] = str_replace('\\', '\\\\', $toClass($new));
}
uksort($pairs, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
foreach (array_slice($argv, 2) as $file) {
    $text = file_get_contents($file);
    $before = $text;
    foreach ($pairs as $old => $new) {
        $text = preg_replace_callback('/(?<![A-Za-z0-9\\\\\/])'.preg_quote($old, '/').'(?![A-Za-z0-9])/', fn (): string => $new, $text);
    }
    if ($text !== $before) {
        file_put_contents($file, $text);
        echo "{$file}\n";
    }
}
