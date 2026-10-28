<?php

namespace Tests\Domains\Environments;

use App\Domains\Environments\DotenvText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DotenvTextTest extends TestCase
{
    public function test_it_reads_values_and_skips_empty_lines_and_comments(): void
    {
        $parsed = (new DotenvText)->parse("# a comment\n\nA=1\n  # indented comment\nB=two words\r\nC=\n");

        $this->assertSame(['A' => '1', 'B' => 'two words', 'C' => ''], $parsed->values);
        $this->assertSame(['A' => 3, 'B' => 5, 'C' => 6], $parsed->lines);
        $this->assertSame([], $parsed->errors);
    }

    public function test_export_is_the_same_as_a_plain_line(): void
    {
        $this->assertSame(['A' => '1', 'B' => '2'], (new DotenvText)->parse("export A=1\nB=2")->values);
    }

    public function test_a_quoted_value_loses_its_quotes(): void
    {
        $parsed = (new DotenvText)->parse(<<<'TEXT'
        A="a # b"
        B='it is ${NOT} read'
        C="say \"hi\"\nnext \\ end"
        D="x" # comment
        E=plain # comment
        F=a#b
        TEXT);

        $this->assertSame([], $parsed->errors);
        $this->assertSame([
            'A' => 'a # b',
            'B' => 'it is ${NOT} read',
            'C' => "say \"hi\"\nnext \\ end",
            'D' => 'x',
            'E' => 'plain',
            'F' => 'a#b',
        ], $parsed->values);
    }

    #[DataProvider('badLines')]
    public function test_a_bad_line_is_an_error_with_its_line_number(string $text, string $message): void
    {
        $parsed = (new DotenvText)->parse($text, 3);

        $this->assertSame([$message], $parsed->errors);
    }

    /** @return array<string, array{string, string}> */
    public static function badLines(): array
    {
        $badKey = 'has a key that is not valid. Use letters, digits, and underscores. Do not start with a digit.';

        return [
            'no equals sign' => ["OK=1\nBROKEN", 'Line 4 has no = sign.'],
            'bad key' => ['1BAD=x', "Line 3 {$badKey}"],
            'key with a dash' => ['A-B=x', "Line 3 {$badKey}"],
            'empty key' => ['=x', "Line 3 {$badKey}"],
            'repeated key' => ["A=1\nB=2\nA=3", 'Line 5 repeats the key A from line 3.'],
            'open quote' => ['A="open', 'Line 3 has a quote that does not close.'],
            'open single quote' => ["A='open", 'Line 3 has a quote that does not close.'],
            'text after quote' => ['A="x" y', 'Line 3 has text after the closing quote.'],
        ];
    }

    public function test_it_reports_every_bad_line(): void
    {
        $this->assertCount(2, (new DotenvText)->parse("A\nB=1\nC")->errors);
    }

    public function test_the_text_of_the_formatter_reads_back_to_the_same_values(): void
    {
        $values = [
            'PLAIN' => 'abc-1.2/x:y@z',
            'EMPTY' => '',
            'SPACES' => 'two words',
            'HASH' => '#not a comment',
            'SINGLE' => "it's",
            'NEWLINE' => "a\nb",
            'MIXED' => "q\"uo'te \\ and\nline",
            'DOLLAR' => 'cost ${HOME}',
        ];
        $dotenv = new DotenvText;

        $text = $dotenv->format($values);

        $this->assertStringContainsString("SPACES='two words'\n", $text);
        $this->assertStringContainsString("SINGLE=\"it's\"\n", $text);
        $this->assertSame($values, $dotenv->parse($text)->values);
    }
}
