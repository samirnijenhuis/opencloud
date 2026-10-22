<?php

namespace App\Enums;

enum DnsMode: string
{
    case Manual = 'manual';
    case Cloudflare = 'cloudflare';
}
