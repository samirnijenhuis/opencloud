<?php

namespace Tests\Domains\Builds;

use App\Domains\Applications\Models\Application;
use App\Domains\Builds\Exceptions\BranchNotFound;
use App\Domains\Builds\Git;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class GitTest extends TestCase
{
    use RefreshDatabase;

    public function test_head_throws_branch_not_found_only_when_the_repository_answers_without_the_branch(): void
    {
        Process::fake(['*' => Process::sequence([
            Process::result(''),
            Process::result(errorOutput: "fatal: could not read Username for 'https://github.com': terminal prompts disabled", exitCode: 128),
        ])]);
        $application = new Application(['repository' => 'https://github.com/acme/shop.git']);
        $errors = [];

        foreach ([1, 2] as $call) {
            try {
                Git::head($application, 'develop');
            } catch (RuntimeException $e) {
                $errors[] = $e;
            }
        }

        $this->assertSame([BranchNotFound::class, RuntimeException::class], array_map(fn (RuntimeException $e) => $e::class, $errors));
        $this->assertSame('The branch develop does not exist in https://github.com/acme/shop.git.', $errors[0]->getMessage());
    }
}
