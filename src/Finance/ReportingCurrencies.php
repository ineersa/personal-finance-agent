<?php

declare(strict_types=1);

namespace App\Finance;

final class ReportingCurrencies
{
    // ECB reference currencies, including EUR as the implicit base. No rate import.
    public const array CODES = ['EUR', 'USD', 'JPY', 'CZK', 'DKK', 'GBP', 'HUF', 'PLN', 'RON', 'SEK', 'CHF', 'ISK', 'NOK', 'TRY', 'AUD', 'BRL', 'CAD', 'CNY', 'HKD', 'IDR', 'ILS', 'INR', 'KRW', 'MXN', 'MYR', 'NZD', 'PHP', 'SGD', 'THB', 'ZAR'];

    /** @return array<string, string> */
    public static function choices(): array
    {
        return array_combine(self::CODES, self::CODES);
    }
}
