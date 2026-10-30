<?php

declare(strict_types=1);

namespace App\Enum;

enum Direction: string
{
    case Credit = 'Credit';
    case Debit = 'Debit';
}
