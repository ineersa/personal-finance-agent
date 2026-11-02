<?php

declare(strict_types=1);

namespace App\Enum;

enum InstrumentKind: string
{
    case BankAccount = 'bank_account';
    case CreditCard = 'credit_card';
    case Other = 'other';
}
