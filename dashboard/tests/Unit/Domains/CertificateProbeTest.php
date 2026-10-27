<?php

namespace Tests\Unit\Domains;

use App\Domains\Routing\CertificateProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CertificateProbeTest extends TestCase
{
    private const NOW = 1792838758;

    /** @return array<string, array{?array<string, mixed>, string, bool}> */
    public static function certificates(): array
    {
        $valid = fn (string $names) => ['validFrom_time_t' => self::NOW - 60, 'validTo_time_t' => self::NOW + 86400, 'extensions' => ['subjectAltName' => $names]];

        return [
            'the exact name' => [$valid('DNS:shop.example.com'), 'shop.example.com', true],
            'the name among others' => [$valid('DNS:example.com, DNS:shop.example.com'), 'shop.example.com', true],
            'the name in other letter case' => [$valid('DNS:Shop.Example.com'), 'shop.example.com', true],
            'a wildcard one level up' => [$valid('DNS:*.example.com'), 'shop.example.com', true],
            'a wildcard two levels up' => [$valid('DNS:*.example.com'), 'a.shop.example.com', false],
            'another name' => [$valid('DNS:other.example.com'), 'shop.example.com', false],
            'no names' => [['validFrom_time_t' => self::NOW - 60, 'validTo_time_t' => self::NOW + 86400], 'shop.example.com', false],
            'expired' => [['validFrom_time_t' => self::NOW - 86400, 'validTo_time_t' => self::NOW - 1, 'extensions' => ['subjectAltName' => 'DNS:shop.example.com']], 'shop.example.com', false],
            'not valid yet' => [['validFrom_time_t' => self::NOW + 60, 'validTo_time_t' => self::NOW + 86400, 'extensions' => ['subjectAltName' => 'DNS:shop.example.com']], 'shop.example.com', false],
            'no certificate' => [null, 'shop.example.com', false],
        ];
    }

    /** @param  array<string, mixed>|null  $certificate */
    #[DataProvider('certificates')]
    public function test_a_certificate_covers_a_domain_only_with_its_name_and_inside_its_dates(?array $certificate, string $hostname, bool $expected): void
    {
        $this->assertSame($expected, CertificateProbe::covers($certificate, $hostname, self::NOW));
    }
}
