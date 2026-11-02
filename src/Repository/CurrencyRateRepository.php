<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CurrencyRate;
use App\Enum\ReportingCurrency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CurrencyRate> */
final class CurrencyRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CurrencyRate::class);
    }

    public function rateFor(ReportingCurrency $currency, \DateTimeImmutable $day): string
    {
        if (ReportingCurrency::EUR === $currency) {
            return '1';
        }
        $rate = $this->getEntityManager()->getConnection()->fetchOne('SELECT rate FROM currency_rate WHERE day = ? AND currency = ?', [$day->format('Y-m-d'), $currency->value]);
        if (!\is_string($rate)) {
            throw new \RuntimeException(\sprintf('No %s reference rate stored for %s. Refresh ECB history covering this date.', $currency->value, $day->format('Y-m-d')));
        }

        return $rate;
    }

    public function firstStoredDay(): ?string
    {
        $day = $this->getEntityManager()->getConnection()->fetchOne('SELECT MIN(day) FROM currency_rate');

        return \is_string($day) ? $day : null;
    }

    /** @param non-empty-list<string> $currencies */
    public function firstUnconfirmedDay(string $start, string $published, array $currencies): ?string
    {
        $day = $this->getEntityManager()->getConnection()->fetchOne(<<<'SQL'
            WITH RECURSIVE calendar(day) AS (
                SELECT :start WHERE :start < :published
                UNION ALL
                SELECT date(day, '+1 day') FROM calendar WHERE date(day, '+1 day') < :published
            )
            SELECT calendar.day
            FROM calendar
            LEFT JOIN currency_rate rates ON rates.day = calendar.day AND rates.currency IN (:currencies)
            GROUP BY calendar.day
            HAVING COUNT(rates.currency) < :expected OR MAX(rates.is_provisional) = 1
            ORDER BY calendar.day
            LIMIT 1
            SQL, ['start' => $start, 'published' => $published, 'currencies' => $currencies, 'expected' => \count($currencies)], ['currencies' => ArrayParameterType::STRING, 'expected' => ParameterType::INTEGER]);

        return \is_string($day) ? $day : null;
    }

    /** @param array<string, string> $rates */
    public function saveDay(string $day, array $rates, bool $provisional): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $stored = [];
        foreach ($connection->fetchAllAssociative('SELECT currency, rate, is_provisional FROM currency_rate WHERE day = ?', [$day]) as $row) {
            $stored[(string) $row['currency']] = [(string) $row['rate'], (bool) $row['is_provisional']];
        }
        $changes = [];
        foreach ($rates as $currency => $rate) {
            if (($stored[$currency] ?? null) !== [$rate, $provisional]) {
                $changes[$currency] = $rate;
            }
        }
        if ([] === $changes) {
            return 0;
        }
        $connection->transactional(static function (Connection $connection) use ($day, $changes, $provisional): void {
            foreach ($changes as $currency => $rate) {
                $connection->executeStatement('INSERT INTO currency_rate (day, currency, rate, is_provisional) VALUES (?, ?, ?, ?) ON CONFLICT (day, currency) DO UPDATE SET rate = excluded.rate, is_provisional = excluded.is_provisional', [$day, $currency, $rate, (int) $provisional]);
            }
        });

        return \count($changes);
    }

    /** @param list<array{string, string, string}> $rows */
    public function appendRates(array $rows): void
    {
        $this->getEntityManager()->getConnection()->transactional(static function (Connection $connection) use ($rows): void {
            foreach (array_chunk($rows, 250) as $batch) {
                $connection->executeStatement('INSERT INTO currency_rate (day, currency, rate, is_provisional) VALUES '.implode(', ', array_fill(0, \count($batch), '(?, ?, ?, 0)')).' ON CONFLICT (day, currency) DO NOTHING', array_merge(...$batch));
            }
        });
    }
}
