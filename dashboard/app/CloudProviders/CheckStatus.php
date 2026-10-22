<?php

namespace App\CloudProviders;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Invalid = 'invalid';
    case MissingRights = 'missing_rights';
}
