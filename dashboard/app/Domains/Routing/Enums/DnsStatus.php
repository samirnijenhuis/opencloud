<?php

namespace App\Domains\Routing\Enums;

use Symfony\Component\HttpFoundation\IpUtils;

enum DnsStatus: string
{
    case PENDING = 'pending';
    /** An A record points at the ingress IP of the cluster. */
    case OK = 'ok';
    /** Every A record is a Cloudflare address, so Cloudflare forwards to the cluster. */
    case PROXIED = 'proxied';
    case WRONG = 'wrong';
    case MISSING = 'missing';

    /** Cloudflare publishes these at https://www.cloudflare.com/ips-v4. */
    public const CLOUDFLARE_IPV4 = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ];

    /** @param  list<string>  $addresses  the A records of the hostname */
    public static function forAddresses(array $addresses, ?string $ingressIp): self
    {
        return match (true) {
            $addresses === [] => self::MISSING,
            $ingressIp !== null && in_array($ingressIp, $addresses, true) => self::OK,
            collect($addresses)->every(fn (string $address) => IpUtils::checkIp($address, self::CLOUDFLARE_IPV4)) => self::PROXIED,
            default => self::WRONG,
        };
    }

    public function routable(): bool
    {
        return $this === self::OK || $this === self::PROXIED;
    }
}
