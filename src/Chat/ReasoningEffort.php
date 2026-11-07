<?php

declare(strict_types=1);

namespace App\Chat;

enum ReasoningEffort: string
{
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case ExtraHigh = 'xhigh';
    case Max = 'max';
}
