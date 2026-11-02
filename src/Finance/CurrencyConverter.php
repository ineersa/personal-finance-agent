<?php

declare(strict_types=1);

namespace App\Finance;

use App\Enum\ReportingCurrency;
use Doctrine\DBAL\Connection;

final readonly class CurrencyConverter
{
    // Calculation precision, not display rounding; format_currency owns presentation.
    private const int SCALE = 20;

    public function __construct(private Connection $connection)
    {
    }

    public function convert(string $amount, ReportingCurrency $source, ReportingCurrency $target, \DateTimeImmutable $day): string
    {
        if (1 !== preg_match('/\A[+-]?\d+(?:\.\d+)?\z/D', $amount)) {
            throw new \InvalidArgumentException('Conversion requires a plain decimal amount.');
        }
        if ($source === $target) {
            return $amount;
        }
        $sourceRate = $this->rate($source, $day);
        $targetRate = $this->rate($target, $day);
        // Retain every fractional digit in the multiplication before division.
        $scale = $this->fractionDigits($amount) + $this->fractionDigits($targetRate);

        return bcdiv(bcmul($amount, $targetRate, $scale), $sourceRate, max(self::SCALE, $this->fractionDigits($amount)));
    }

    private function rate(ReportingCurrency $currency, \DateTimeImmutable $day): string
    {
        if (ReportingCurrency::EUR === $currency) {
            return '1';
        }
        $rate = $this->connection->fetchOne('SELECT units_per_eur FROM currency_rate WHERE day = ? AND currency = ?', [$day->format('Y-m-d'), $currency->value]);
        if (!\is_string($rate)) {
            throw new \RuntimeException(sprintf('No %s reference rate stored for %s. Import ECB history covering this date.', $currency->value, $day->format('Y-m-d')));
        }

        return $rate;
    }

    private function fractionDigits(string $value): int
    {
        $point = strpos($value, '.');

        return false === $point ? 0 : \strlen($value) - $point - 1;
    }
}
