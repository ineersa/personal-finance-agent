<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Fidry\AliceDataFixtures\ProcessorInterface;

final readonly class CurrencyRateProcessor implements ProcessorInterface
{
    public function __construct(private Connection $connection)
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
        $first = new \DateTimeImmutable(array_key_first($history));
        $last = new \DateTimeImmutable(array_key_last($history));
        $previous = [];
        $rows = [];
        for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $previous = $history[$date] ?? $previous;
            foreach ($previous as $currency => $rate) {
                $rows[] = [$date, $currency, $rate];
            }
        }
        // Native Alice post-processing avoids ORM's date-object composite-ID hashing.
        // Parse/expand before writing; participate in any existing fixture transaction.
        $this->connection->transactional(static function (Connection $connection) use ($rows): void {
            foreach (array_chunk($rows, 250) as $batch) {
                $connection->executeStatement('INSERT INTO currency_rate (day, currency, units_per_eur, is_provisional) VALUES '.implode(', ', array_fill(0, \count($batch), '(?, ?, ?, 0)')).' ON CONFLICT (day, currency) DO NOTHING', array_merge(...$batch));
            }
        });
    }
}
