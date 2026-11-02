<?php

declare(strict_types=1);

namespace App\Finance;

use App\Enum\ReportingCurrency;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class EcbRateRefresher
{
    public function __construct(private HttpClientInterface $client, private Connection $connection)
    {
    }

    public function refresh(): int
    {
        $daily = $this->fetch('eurofxref-daily.xml');
        $published = array_key_last($daily);
        $currencies = array_keys($daily[$published]);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        if ($published > $today->format('Y-m-d')) {
            throw new \RuntimeException('ECB publication date is in the future.');
        }
        $start = (new \DateTimeImmutable($published))->modify('-89 days')->format('Y-m-d');
        $firstStored = $this->connection->fetchOne('SELECT MIN(day) FROM currency_rate');
        if (\is_string($firstStored) && $firstStored > $start) {
            $start = $firstStored;
        }
        $stored = [];
        foreach ($this->connection->fetchAllAssociative('SELECT day, currency, units_per_eur, is_provisional FROM currency_rate WHERE day >= ?', [$start]) as $row) {
            $stored[(string) $row['day']][(string) $row['currency']] = [(string) $row['units_per_eur'], (bool) $row['is_provisional']];
        }
        $firstMissing = null;
        // Trailing dates after the latest publication are not evidence of missed rates.
        for ($day = new \DateTimeImmutable($start); $day->format('Y-m-d') < $published; $day = $day->modify('+1 day')) {
            foreach ($currencies as $currency) {
                if (!isset($stored[$day->format('Y-m-d')][$currency]) || $stored[$day->format('Y-m-d')][$currency][1]) {
                    $firstMissing = $day->format('Y-m-d');
                    break 2;
                }
            }
        }
        $history = $daily;
        if (null !== $firstMissing) {
            $history = $this->fetch('eurofxref-hist-90d.xml');
            if (array_key_last($history) !== $published) {
                throw new \RuntimeException('ECB recent history has not caught up with the daily publication.');
            }
            if (\is_string($firstStored) && array_key_first($history) > $firstMissing) {
                throw new \RuntimeException('ECB recent history does not cover the missing date. Restore canonical historical fixtures.');
            }
            foreach ($history as $rates) {
                if (array_keys($rates) !== $currencies) {
                    throw new \RuntimeException('ECB recent history has incomplete currency coverage.');
                }
            }
            $history[$published] = $daily[$published];
            // Successful official history confirms intervening nonpublication days.
            // Never manufacture coverage before its first available reference date.
            $start = max($start, array_key_first($history));
        } else {
            $start = $published;
        }
        $previous = [];
        foreach ($history as $date => $rates) {
            if ($date < $start) {
                $previous = $rates;
            }
        }
        $count = 0;
        for ($day = new \DateTimeImmutable($start); $day <= $today; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $previous = $history[$date] ?? $previous;
            $provisional = $date > $published;
            $changes = [];
            foreach ($previous as $currency => $rate) {
                if (($stored[$date][$currency] ?? null) !== [$rate, $provisional]) {
                    $changes[$currency] = $rate;
                }
            }
            if ([] === $changes) {
                continue;
            }
            // All HTTP and parsing have completed; only changed rows enter short writes.
            $this->connection->transactional(static function (Connection $connection) use ($date, $changes, $provisional): void {
                foreach ($changes as $currency => $rate) {
                    $connection->executeStatement('INSERT INTO currency_rate (day, currency, units_per_eur, is_provisional) VALUES (?, ?, ?, ?) ON CONFLICT (day, currency) DO UPDATE SET units_per_eur = excluded.units_per_eur, is_provisional = excluded.is_provisional', [$date, $currency, $rate, (int) $provisional]);
                }
            });
            $count += \count($changes);
        }

        return $count;
    }

    /** @return non-empty-array<string, non-empty-array<string, string>> */
    private function fetch(string $filename): array
    {
        $xml = $this->client->request('GET', 'https://www.ecb.europa.eu/stats/eurofxref/'.$filename)->getContent();
        $document = new \DOMDocument();
        if (!@$document->loadXML($xml, \LIBXML_NONET)) {
            throw new \RuntimeException('Invalid ECB reference-rate XML.');
        }
        $history = [];
        foreach ($document->getElementsByTagName('Cube') as $cube) {
            if (!$cube->hasAttribute('time')) {
                continue;
            }
            $date = $cube->getAttribute('time');
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (false === $parsed || $date !== $parsed->format('Y-m-d')) {
                throw new \RuntimeException('Invalid ECB reference date.');
            }
            $rates = [];
            foreach ($cube->childNodes as $rate) {
                if (!$rate instanceof \DOMElement || !$rate->hasAttribute('currency')) {
                    continue;
                }
                $currency = $rate->getAttribute('currency');
                if (null === ReportingCurrency::tryFrom($currency) || 'EUR' === $currency) {
                    continue;
                }
                $value = $rate->getAttribute('rate');
                if (1 !== preg_match('/\A\d+(?:\.\d+)?\z/D', $value) || 0 >= bccomp($value, '0', \strlen($value))) {
                    throw new \RuntimeException('Invalid ECB reference rate.');
                }
                $rates[$currency] = $value;
            }
            if ([] === $rates) {
                throw new \RuntimeException('ECB publication contains no supported currencies.');
            }
            foreach (ReportingCurrency::cases() as $currency) {
                if (ReportingCurrency::EUR !== $currency && !isset($rates[$currency->value])) {
                    throw new \RuntimeException('ECB publication has incomplete currency coverage.');
                }
            }
            ksort($rates);
            $history[$date] = $rates;
        }
        if ([] === $history) {
            throw new \RuntimeException('ECB reference-rate history is empty.');
        }
        ksort($history);

        return $history;
    }
}
