<?php

declare(strict_types=1);

namespace App\Finance;

use App\Enum\ReportingCurrency;
use App\Repository\CurrencyRateRepository;

final readonly class CurrencyConverter
{
    // Calculation precision, not display rounding; format_currency owns presentation.
    private const int SCALE = 20;

    public function __construct(private CurrencyRateRepository $rates)
    {
    }

    public function convert(string $amount, ReportingCurrency $source, ReportingCurrency $target, \DateTimeImmutable $day): string
    {
        if ($source === $target) {
            return bcmul($amount, '1', $this->fractionDigits($amount));
        }
        $sourceRate = $this->rates->rateFor($source, $day);
        $targetRate = $this->rates->rateFor($target, $day);
        // Retain every fractional digit in the multiplication before division.
        $scale = $this->fractionDigits($amount) + $this->fractionDigits($targetRate);

        return bcdiv(bcmul($amount, $targetRate, $scale), $sourceRate, max(self::SCALE, $this->fractionDigits($amount)));
    }

    private function fractionDigits(string $value): int
    {
        $point = strpos($value, '.');

        return false === $point ? 0 : \strlen($value) - $point - 1;
    }
}
