<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use App\Repository\CurrencyRateRepository;
use Fidry\AliceDataFixtures\ProcessorInterface;

final readonly class CurrencyRateProcessor implements ProcessorInterface
{
    public function __construct(private CurrencyRateRepository $rates)
    {
    }

    public function preProcess(string $id, object $object): void
    {
    }

    public function postProcess(string $id, object $object): void
    {
        if ('demo' !== $id || !$object instanceof User) {
            return;
        }
        /** @var non-empty-array<string, array<string, string>> $history */
        $history = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/resources/ecb/reference-rates.json'), true, flags: \JSON_THROW_ON_ERROR);
        $first = new \DateTimeImmutable(array_key_first($history), new \DateTimeZone('UTC'));
        $last = new \DateTimeImmutable(array_key_last($history), new \DateTimeZone('UTC'));
        $previous = [];
        $rows = [];
        for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $previous = $history[$date] ?? $previous;
            foreach ($previous as $currency => $rate) {
                $rows[] = [$date, $currency, $rate];
            }
        }
        // Bulk native Alice post-processing avoids thousands of ORM fixture objects.
        // Parse/expand before writing; participate in any existing fixture transaction.
        $this->rates->appendRates($rows);
    }
}
