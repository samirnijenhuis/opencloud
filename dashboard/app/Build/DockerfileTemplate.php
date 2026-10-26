<?php

namespace App\Build;

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
        $text = file_get_contents(dirname(__DIR__, 2)."/resources/docker/{$template}.Dockerfile");

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
}
