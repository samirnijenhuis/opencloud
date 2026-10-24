<?php

namespace App\Support;

/**
 * Tools such as artisan print terminal colors, which the UI would show as raw codes.
 */
final class TerminalText
{
    public static function plain(string $text): string
    {
        return preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $text);
    }
}
