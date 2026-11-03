<?php

declare(strict_types=1);

namespace App\ChatGpt;

enum ConnectionStatus: string
{
    case Unavailable = 'unavailable';
    case Disconnected = 'disconnected';
    case Permission = 'permission';
    case Reconnect = 'reconnect';
    case Connected = 'connected';
    case Failed = 'failed';
}
