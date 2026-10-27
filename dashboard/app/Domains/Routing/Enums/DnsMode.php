<?php

namespace App\Domains\Routing\Enums;

enum DnsMode: string
{
    case Manual = 'manual';
    case Cloudflare = 'cloudflare';
}
