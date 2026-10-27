<?php

namespace Tests\Domains\Builds;

use App\Domains\Builds\DockerfileTemplate;
use App\Domains\Builds\LaravelDockerfile;
use App\Domains\Builds\NodeDockerfile;
use App\Domains\Builds\NodeProject;
use App\Domains\Builds\PythonDockerfile;
use App\Domains\Builds\PythonProject;
use App\Domains\Builds\RepositoryFiles;
use App\Domains\Builds\RubyDockerfile;
use App\Domains\Builds\RubyProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DockerfileTemplateTest extends TestCase
{
    private const COMMAND = "npm run lint\n# the next command needs the lint\nnpm run build";

    public function test_a_build_command_with_several_lines_is_one_run_that_stops_at_the_first_failure(): void
    {
        $this->assertSame(
            "RUN --mount=type=secret,id=A,env=A <<'OPENCLOUD_BUILD'\nset -e\nnpm run lint\nnpm run build\nOPENCLOUD_BUILD",
            DockerfileTemplate::run("\n  npm run lint\nnpm run build\n", DockerfileTemplate::secretMounts(['A'])),
        );
    }

    public function test_an_empty_build_command_gives_no_run(): void
    {
        $this->assertSame('', DockerfileTemplate::run(" \n "));
        $this->assertSame('', DockerfileTemplate::run(null));
    }

    /** @return array<string, array{string}> */
    public static function dockerfiles(): array
    {
        $files = fn (array $files) => new RepositoryFiles(fn (string $path) => $files[$path] ?? null);

        return [
            'laravel' => [(new LaravelDockerfile(['8.4'], 'php:{php}'))->render(['require' => []], null, [], self::COMMAND)],
            'node' => [NodeDockerfile::render(new NodeProject(['scripts' => ['start' => 'node server.js']], null), [], self::COMMAND)],
            'python' => [PythonDockerfile::render(PythonProject::in($files(['requirements.txt' => "gunicorn\n", 'Procfile' => "web: gunicorn app:app\n"])), [], self::COMMAND)],
            'ruby' => [RubyDockerfile::render(RubyProject::in($files(['Gemfile' => '', 'config.ru' => ''])), [], self::COMMAND)],
        ];
    }

    #[DataProvider('dockerfiles')]
    public function test_each_template_runs_both_commands_in_one_heredoc_run(string $dockerfile): void
    {
        $this->assertStringContainsString(
            "RUN <<'OPENCLOUD_BUILD'\nset -e\nnpm run lint\n# the next command needs the lint\nnpm run build\nOPENCLOUD_BUILD\n",
            $dockerfile,
        );
        $this->assertSame(2, substr_count($dockerfile, "\nOPENCLOUD_BUILD\n") + substr_count($dockerfile, "<<'OPENCLOUD_BUILD'\n"));
    }
}
