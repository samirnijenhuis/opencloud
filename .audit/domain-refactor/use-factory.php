<?php

$dashboard = dirname(__DIR__, 2).'/dashboard';
foreach (glob("{$dashboard}/app/Domains/*/Models/*.php") as $path) {
    $source = file_get_contents($path);
    if (! str_contains($source, 'use HasFactory') || str_contains($source, '#[UseFactory')) {
        continue;
    }
    $model = basename($path, '.php');
    $factory = "{$model}Factory";
    if (! is_file("{$dashboard}/database/factories/{$factory}.php")) {
        fwrite(STDERR, "no factory for {$model}\n");
        exit(1);
    }
    $source = preg_replace('/^((?:final |abstract )?class '.$model.'\b)/m', "#[UseFactory({$factory}::class)]\n$1", $source, 1, $count);
    preg_match_all('/^use [^;(]+;\n/m', $source, $matches, PREG_OFFSET_CAPTURE);
    [$text, $position] = end($matches[0]);
    $imports = implode('', array_filter(
        ["use Database\\Factories\\{$factory};\n", "use Illuminate\\Database\\Eloquent\\Attributes\\UseFactory;\n"],
        fn (string $line): bool => ! str_contains($source, $line),
    ));
    $source = substr($source, 0, $position + strlen($text)).$imports.substr($source, $position + strlen($text));
    if ($count !== 1) {
        fwrite(STDERR, "no class line in {$path}\n");
        exit(1);
    }
    file_put_contents($path, $source);
    echo "{$model}\n";
}
