<?php

namespace App\Domains;

/**
 * Tests bind a subclass that returns scripted addresses.
 */
class DnsResolver
{
    /** @return list<string> the addresses of the A records, sorted */
    public function ipv4(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_A);
        $addresses = $records === false ? [] : array_values(array_unique(array_column($records, 'ip')));
        sort($addresses);

        return $addresses;
    }
}
