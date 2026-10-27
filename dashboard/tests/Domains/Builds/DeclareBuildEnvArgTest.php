<?php

namespace Tests\Domains\Builds;

use App\Domains\Builds\Builder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeclareBuildEnvArgTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function dockerfiles(): array
    {
        return [
            'one stage' => ["FROM nginx:alpine\nCOPY . /usr/share/nginx/html\n", "FROM nginx:alpine\nARG X\nCOPY . /usr/share/nginx/html\n"],
            'two stages and a parser directive' => [
                "# syntax=docker/dockerfile:1\nFROM node:22 AS build\nRUN npm run build\nFROM nginx\n",
                "# syntax=docker/dockerfile:1\nFROM node:22 AS build\nARG X\nRUN npm run build\nFROM nginx\nARG X\n",
            ],
            'lower case and indented' => ["  from php:8.4 as base\n", "  from php:8.4 as base\nARG X\n"],
            'a FROM over two lines' => ["FROM --platform=linux/amd64 \\\n    php:8.4\nRUN true\n", "FROM --platform=linux/amd64 \\\n    php:8.4\nARG X\nRUN true\n"],
            'FROM inside a continued RUN' => ["FROM alpine\nRUN echo \\\nFROM is text here\n", "FROM alpine\nARG X\nRUN echo \\\nFROM is text here\n"],
            'no FROM' => ["# empty\n", "# empty\n"],
        ];
    }

    #[DataProvider('dockerfiles')]
    public function test_each_stage_declares_the_arg(string $dockerfile, string $expected): void
    {
        $this->assertSame($expected, Builder::declareInEachStage($dockerfile, 'X'));
    }
}
