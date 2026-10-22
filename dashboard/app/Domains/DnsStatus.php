<?php

namespace App\Domains;

enum DnsStatus: string
{
    case Pending = 'pending';
    /** An A record points at the ingress IP of the cluster. */
    case Ok = 'ok';
    /** Every A record is a Cloudflare address, so Cloudflare forwards to the cluster. */
    case Proxied = 'proxied';
    case Wrong = 'wrong';
    case Missing = 'missing';

    public function routable(): bool
    {
        return $this === self::Ok || $this === self::Proxied;
    }
}
