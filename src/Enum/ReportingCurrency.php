<?php

declare(strict_types=1);

namespace App\Enum;

// ECB reference currencies plus its implicit EUR base. Original currencies are unrestricted.
enum ReportingCurrency: string
{
    case EUR = 'EUR';
    case USD = 'USD';
    case JPY = 'JPY';
    case CZK = 'CZK';
    case DKK = 'DKK';
    case GBP = 'GBP';
    case HUF = 'HUF';
    case PLN = 'PLN';
    case RON = 'RON';
    case SEK = 'SEK';
    case CHF = 'CHF';
    case ISK = 'ISK';
    case NOK = 'NOK';
    case TRY = 'TRY';
    case AUD = 'AUD';
    case BRL = 'BRL';
    case CAD = 'CAD';
    case CNY = 'CNY';
    case HKD = 'HKD';
    case IDR = 'IDR';
    case ILS = 'ILS';
    case INR = 'INR';
    case KRW = 'KRW';
    case MXN = 'MXN';
    case MYR = 'MYR';
    case NZD = 'NZD';
    case PHP = 'PHP';
    case SGD = 'SGD';
    case THB = 'THB';
    case ZAR = 'ZAR';
}
