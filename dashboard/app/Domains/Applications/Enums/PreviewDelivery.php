<?php

namespace App\Domains\Applications\Enums;

/** Whether a pull request can open a preview. */
enum PreviewDelivery: string
{
    case ON = 'on';
    case NOT_CONNECTED = 'not_connected';
    case OFF = 'off';
}
