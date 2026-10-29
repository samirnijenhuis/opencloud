<?php

namespace Tests\Domains\Routing;

use App\Domains\Routing\Enums\DnsStatus;
use App\Domains\Routing\Models\Domain;
use App\Domains\Routing\PrimaryDomain;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PrimaryDomainTest extends TestCase
{
    private const FREE = ['generated' => true, 'dns_status' => DnsStatus::OK, 'certificate_status' => 'issued'];

    private const LIVE = ['dns_status' => DnsStatus::OK, 'certificate_status' => 'issued'];

    /** @return array<string, array{array<int, array{string, array<string, mixed>}>, ?string}> */
    public static function domains(): array
    {
        return [
            'the domain that a user made primary wins, also when it is not live' => [
                [1 => ['shop-production.203.0.113.10.sslip.io', self::FREE], 2 => ['shop.example.com', self::LIVE], 3 => ['new.example.com', ['is_primary' => true]]],
                'new.example.com',
            ],
            'the oldest live custom domain comes before the free domain' => [
                [1 => ['shop-production.203.0.113.10.sslip.io', self::FREE], 2 => ['pending.example.com', []], 4 => ['www.example.com', self::LIVE], 3 => ['shop.example.com', self::LIVE]],
                'shop.example.com',
            ],
            'a proxied custom domain with a certificate is live' => [
                [1 => ['shop-production.203.0.113.10.sslip.io', self::FREE], 2 => ['shop.example.com', ['dns_status' => DnsStatus::PROXIED, 'certificate_status' => 'issued']]],
                'shop.example.com',
            ],
            'a custom domain without a certificate is not live, so the free domain wins' => [
                [1 => ['shop.example.com', ['dns_status' => DnsStatus::OK]], 2 => ['shop-production.203.0.113.10.sslip.io', self::FREE]],
                'shop-production.203.0.113.10.sslip.io',
            ],
            'without a live custom domain and a free domain, the oldest domain wins' => [
                [5 => ['www.example.com', []], 2 => ['pr-12.preview.example.com', ['dns_status' => DnsStatus::MISSING]]],
                'pr-12.preview.example.com',
            ],
            'an environment without a domain has no primary domain' => [[], null],
        ];
    }

    /** @param  array<int, array{string, array<string, mixed>}>  $domains */
    #[DataProvider('domains')]
    public function test_the_primary_domain_follows_the_user_choice_then_the_live_custom_domain_then_the_free_domain(array $domains, ?string $expected): void
    {
        $models = new Collection;

        foreach ($domains as $id => [$hostname, $attributes]) {
            $domain = new Domain(['hostname' => $hostname, ...$attributes]);
            $domain->id = $id;
            $models->push($domain);
        }

        $this->assertSame($expected, PrimaryDomain::of($models)?->hostname);
    }
}
