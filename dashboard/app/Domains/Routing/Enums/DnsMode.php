<?php

namespace App\Domains\Routing\Enums;

enum DnsMode: string
{
    case MANUAL = 'manual';
    case CLOUDFLARE = 'cloudflare';
}
