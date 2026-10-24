<?php

namespace Tests\Unit\Environments;

use App\Environments\DefaultDomain;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DefaultDomainTest extends TestCase
{
    /** @return array<string, array{string, string, ?int, string}> */
    public static function names(): array
    {
        return [
            'plain names' => ['shop', 'production', null, 'shop-production.203.0.113.10.sslip.io'],
            'capitals, spaces, and signs' => ['My Shop!', 'Production', null, 'my-shop-production.203.0.113.10.sslip.io'],
            'accents and an ampersand' => ['Café & Bar', 'staging', null, 'cafe-bar-staging.203.0.113.10.sslip.io'],
            'dots and underscores' => ['api.v2_beta', 'production', null, 'apiv2-beta-production.203.0.113.10.sslip.io'],
            'nothing left of the name' => ['🚀🚀', 'production', null, 'app-production.203.0.113.10.sslip.io'],
            'nothing left of either name' => ['???', '!!!', null, 'app-env.203.0.113.10.sslip.io'],
            'the application id when the plain name is taken' => ['shop', 'production', 7, 'shop-7-production.203.0.113.10.sslip.io'],
            'a long application name' => [
                'the-very-long-name-of-an-application-that-goes-on-and-on-and-on-forever',
                'production',
                null,
                'the-very-long-name-of-an-application-that-goes-on-an-production.203.0.113.10.sslip.io',
            ],
            'a long name with the id keeps the id' => [
                str_repeat('a', 80),
                'production',
                1234,
                str_repeat('a', 47).'-1234-production.203.0.113.10.sslip.io',
            ],
            'a cut that ends on a dash' => [
                'the-very-long-name-of-an-application-that-goes-on-a-b-c-d',
                'production',
                null,
                'the-very-long-name-of-an-application-that-goes-on-a-production.203.0.113.10.sslip.io',
            ],
            'a long environment name' => ['shop', 'feature-branch-with-a-very-long-name', null, 'shop-feature-branch-with.203.0.113.10.sslip.io'],
        ];
    }

    #[DataProvider('names')]
    public function test_the_hostname_is_dns_safe_and_each_label_is_at_most_63_characters(string $application, string $environment, ?int $id, string $expected): void
    {
        $hostname = DefaultDomain::hostname($application, $environment, '203.0.113.10', $id);

        $this->assertSame($expected, $hostname);
        $this->assertLessThanOrEqual(63, strlen(explode('.', $hostname)[0]));
    }
}
