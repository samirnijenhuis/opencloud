<?php

namespace App\Domains\Builds;

/**
 * Fills one template of resources/docker. A section between {{#name}} and {{/name}} stays when its flag is true.
 * A placeholder {{ name }} that fills with nothing removes its whole line.
 */
final class DockerfileTemplate
{
    /**
     * @param  array<string, bool>  $sections
     * @param  array<string, string>  $values
     */
    public static function render(string $template, array $sections, array $values): string
    {
        $text = file_get_contents(dirname(__DIR__, 3)."/resources/docker/{$template}.Dockerfile");

        foreach ($sections as $name => $keep) {
            $pattern = '/^\{\{#'.$name.'\}\}\n(.*?)^\{\{\/'.$name.'\}\}\n/ms';
            $text = preg_replace($pattern, $keep ? '$1' : '', $text);
        }

        foreach ($values as $name => $value) {
            $placeholder = '{{ '.$name.' }}';
            $text = $value === ''
                ? str_replace(["{$placeholder}\n", $placeholder], '', $text)
                : str_replace($placeholder, $value, $text);
        }

        return $text;
    }

    /**
     * The JSON form of CMD, which runs the command without a shell around it.
     *
     * @param  list<string>  $command
     */
    public static function execForm(array $command): string
    {
        return '['.implode(', ', array_map(fn (string $arg) => json_encode($arg, JSON_UNESCAPED_SLASHES), $command)).']';
    }

    /**
     * The mounts that put each build value in the env of one RUN.
     *
     * @param  list<string>  $keys
     */
    public static function secretMounts(array $keys): string
    {
        return implode('', array_map(fn (string $key) => "--mount=type=secret,id={$key},env={$key} ", $keys));
    }

    /**
     * One RUN for a build command. A command with several lines runs as a script that stops at the first failing command.
     * A heredoc keeps a newline from ending the instruction, and the quoted delimiter stops BuildKit from expanding $VARIABLES.
     */
    public static function run(?string $command, string $mounts = ''): string
    {
        $command = trim((string) $command);

        return $command === '' ? '' : "RUN {$mounts}<<'OPENCLOUD_BUILD'\nset -e\n{$command}\nOPENCLOUD_BUILD";
    }
}
