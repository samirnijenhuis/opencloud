<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Console\Command;

final class CommandsTest extends ArchitectureTestCase
{
    public function test_commands_have_no_command_suffix(): void
    {
        $this->assertMatchesBaseline(
            'commands.no-command-suffix',
            'Name a console command by its verb phrase, without a Command suffix: CopyDatabase. See .claude/rules/naming.md.',
            $this->namedWith($this->subclassesOf(Command::class), 'Command'),
        );
    }

    public function test_commands_live_in_the_console_commands_directory(): void
    {
        $this->assertMatchesBaseline(
            'commands.placement',
            'Put a console command in app/Console/Commands. See .claude/rules/architecture.md.',
            $this->placedOutside(
                $this->subclassesOf(Command::class),
                '/^App\\\\Console\\\\Commands\\\\\w+$/',
            ),
        );
    }
}
