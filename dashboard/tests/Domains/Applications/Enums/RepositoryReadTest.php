<?php

namespace Tests\Domains\Applications\Enums;

use App\Domains\Applications\Enums\RepositoryRead;
use PHPUnit\Framework\TestCase;

class RepositoryReadTest extends TestCase
{
    public function test_only_the_three_read_paths_work(): void
    {
        $this->assertSame(
            ['github_app', 'deploy_key', 'public'],
            array_column(array_filter(RepositoryRead::cases(), fn (RepositoryRead $read) => $read->works()), 'value'),
        );
    }
}
