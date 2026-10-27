<?php

namespace App\Domains\CloudProviders\Enums;

enum CheckStatus: string
{
    case OK = 'ok';
    case INVALID = 'invalid';
    case MISSING_RIGHTS = 'missing_rights';
}
