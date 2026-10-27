<?php

declare(strict_types=1);

namespace Tests\Architecture;

final class TestsTest extends ArchitectureTestCase
{
    private const array EXEMPT = ['Architecture', 'Concerns', 'Fakes', 'Fixtures'];

    public function test_tests_mirror_the_app_tree(): void
    {
        $violations = [];

        foreach ($this->phpFiles('tests') as $path) {
            $file = substr($this->relative($path), strlen('tests/'));
            $directory = dirname($file);

            if ($file !== 'TestCase.php'
                && ! in_array(explode('/', $file)[0], self::EXEMPT, true)
                && ($directory === '.' || ! is_dir($this->absolute($this->mirrorOf($directory))))) {
                $violations[] = $this->relative($path).':1';
            }
        }

        $this->assertMatchesBaseline(
            'tests.mirror-source',
            'Put the test in the tests/ directory that mirrors the class under test: tests/Domains/Clusters/Actions for app/Domains/Clusters/Actions, tests/Database/Migrations for database/migrations. Shared test traits go in tests/Concerns. See .claude/rules/testing.md.',
            $violations,
        );
    }

    private function mirrorOf(string $directory): string
    {
        return str_starts_with($directory, 'Database') ? strtolower($directory) : "app/{$directory}";
    }
}
