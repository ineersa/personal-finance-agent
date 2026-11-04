<?php

declare(strict_types=1);

namespace App\Repository;

use App\Finance\Entity\CurrencyRate;
use App\Enum\ReportingCurrency;
use Doctrine\ORM\EntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;

/** @extends EntityRepository<CurrencyRate> */
final class CurrencyRateRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager, $entityManager->getClassMetadata(CurrencyRate::class));
    }

    public function rateFor(ReportingCurrency $currency, \DateTimeImmutable $day): string
    {
        if (ReportingCurrency::EUR === $currency) {
            return '1';
        }
        $rate = $this->createQueryBuilder('r')
            ->select('r.rate')
            ->where('r.day = :day')
            ->andWhere('r.currency = :currency')
            ->setParameter('day', $day, Types::DATE_IMMUTABLE)
            ->setParameter('currency', $currency->value)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);
        if (!\is_string($rate)) {
            throw new \RuntimeException(\sprintf('No %s reference rate stored for %s. Refresh ECB history covering this date.', $currency->value, $day->format('Y-m-d')));
        }

        return $rate;
    }

    public function firstStoredDay(): ?string
    {
        $day = $this->createQueryBuilder('r')->select('MIN(r.day)')->getQuery()->getSingleScalarResult();

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
        $rows = $this->createQueryBuilder('r')
            ->select('r.currency', 'r.rate', 'r.isProvisional')
            ->where('r.day = :day')
            ->setParameter('day', new \DateTimeImmutable($day, new \DateTimeZone('UTC')), Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $stored[$row['currency']] = [$row['rate'], $row['isProvisional']];
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
