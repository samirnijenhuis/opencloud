<?php

namespace App\Domains\Environments;

/** Turns env values into dotenv text and dotenv text back into env values. The text is a line for each value: KEY=value. */
class DotenvText
{
    private const KEY = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** A value with only these characters needs no quotes. */
    private const BARE = '/^[A-Za-z0-9_.\/:@%+,=-]*$/';

    /**
     * @param  int  $firstLine  the number that the first line of the text has on the page, for the messages
     */
    public function parse(string $text, int $firstLine = 1): ParsedDotenv
    {
        $values = [];
        $lines = [];
        $errors = [];

        foreach (preg_split('/\r\n|\n/', $text) as $index => $raw) {
            $number = $firstLine + $index;
            $line = trim($raw);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $line = preg_replace('/^export\s+/', '', $line);

            if (! str_contains($line, '=')) {
                $errors[] = "Line {$number} has no = sign.";

                continue;
            }

            [$key, $rest] = explode('=', $line, 2);
            $key = rtrim($key);

            if (preg_match(self::KEY, $key) !== 1) {
                $errors[] = "Line {$number} has a key that is not valid. Use letters, digits, and underscores. Do not start with a digit.";

                continue;
            }

            if (isset($lines[$key])) {
                $errors[] = "Line {$number} repeats the key {$key} from line {$lines[$key]}.";

                continue;
            }

            $value = $this->value(ltrim($rest), $error);

            if ($error !== null) {
                $errors[] = "Line {$number} {$error}";

                continue;
            }

            $values[$key] = $value;
            $lines[$key] = $number;
        }

        return new ParsedDotenv($values, $lines, $errors);
    }

    /** @param  array<string, string>  $values */
    public function format(array $values): string
    {
        $text = '';

        foreach ($values as $key => $value) {
            $text .= $key.'='.$this->quote($value)."\n";
        }

        return $text;
    }

    private function value(string $rest, ?string &$error): string
    {
        $error = null;
        $quote = $rest[0] ?? '';

        if ($quote !== '"' && $quote !== "'") {
            return trim(preg_replace('/\s+#.*$/', '', $rest));
        }

        $body = $quote === '"' ? '(?:\\\\.|[^"\\\\])*' : "[^']*";

        if (preg_match('/^'.$quote.'('.$body.')'.$quote.'(.*)$/', $rest, $found) !== 1) {
            $error = 'has a quote that does not close.';

            return '';
        }

        $after = trim($found[2]);

        if ($after !== '' && ! str_starts_with($after, '#')) {
            $error = 'has text after the closing quote.';

            return '';
        }

        if ($quote === "'") {
            return $found[1];
        }

        return preg_replace_callback('/\\\\(["\\\\n])/', fn (array $escape) => $escape[1] === 'n' ? "\n" : $escape[1], $found[1]);
    }

    private function quote(string $value): string
    {
        if (preg_match(self::BARE, $value) === 1) {
            return $value;
        }

        if (! str_contains($value, "'") && ! str_contains($value, "\n")) {
            return "'{$value}'";
        }

        return '"'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value).'"';
    }
}
