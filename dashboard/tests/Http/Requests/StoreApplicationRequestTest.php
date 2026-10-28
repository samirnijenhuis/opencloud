<?php

namespace Tests\Http\Requests;

use App\Http\Requests\StoreApplicationRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreApplicationRequestTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function repositories(): array
    {
        return [
            'owner/repo' => ['acme/shop', 'https://github.com/acme/shop.git'],
            'owner/repo.git' => ['acme/shop.git', 'https://github.com/acme/shop.git'],
            'the SSH address' => ['git@github.com:acme/shop.git', 'https://github.com/acme/shop.git'],
            'the SSH address without .git' => [' git@github.com:acme/my.shop ', 'https://github.com/acme/my.shop.git'],
            'an https address' => ['https://gitlab.com/acme/shop.git', 'https://gitlab.com/acme/shop.git'],
            'an SSH address on another host' => ['git@gitlab.com:acme/shop.git', 'git@gitlab.com:acme/shop.git'],
        ];
    }

    #[DataProvider('repositories')]
    public function test_the_repository_field_stores_one_form_for_a_github_repository(string $input, string $stored): void
    {
        $this->assertSame($stored, StoreApplicationRequest::normalizeRepository($input));
    }
}
