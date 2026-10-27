<?php

namespace App\Domains\CloudProviders\Enums;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Invalid = 'invalid';
    case MissingRights = 'missing_rights';
}
