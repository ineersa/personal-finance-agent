<?php

declare(strict_types=1);

namespace App\Finance;

use App\Enum\ReportingCurrency;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\CurrencyRateRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class EcbRateRefresher
{
    public function __construct(private HttpClientInterface $client, private EntityManagerInterface $central, private TenantManagers $tenants)
    {
    }

    public function refresh(): int
    {
        $daily = $this->fetch('eurofxref-daily.xml');
        $history = null;
        $count = 0;
        foreach ($this->central->getRepository(User::class)->findAll() as $owner) {
            if (\in_array('ROLE_ADMIN', $owner->getRoles(), true)) {
                continue;
            }
            $em = $this->tenants->open($owner);
            try {
                $count += $this->refreshTenant(new CurrencyRateRepository($em), $daily, $history);
            } finally {
                $this->tenants->release($em);
            }
        }

        return $count;
    }

    /** @param non-empty-array<string, non-empty-array<string, string>> $daily
     * @param non-empty-array<string, non-empty-array<string, string>>|null $cachedHistory
     */
    private function refreshTenant(CurrencyRateRepository $rates, array $daily, ?array &$cachedHistory): int
    {
        $published = array_key_last($daily);
        $currencies = array_keys($daily[$published]);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        if ($published > $today->format('Y-m-d')) {
            throw new \RuntimeException('ECB publication date is in the future.');
        }
        $utc = new \DateTimeZone('UTC');
        $start = (new \DateTimeImmutable($published, $utc))->modify('-89 days')->format('Y-m-d');
        $firstStored = $rates->firstStoredDay();
        if (null !== $firstStored && $firstStored > $start) {
            $start = $firstStored;
        }
        $firstMissing = $rates->firstUnconfirmedDay($start, $published, $currencies);
        $history = $daily;
        if (null !== $firstMissing) {
            $history = $cachedHistory ??= $this->fetch('eurofxref-hist-90d.xml');
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
        for ($day = new \DateTimeImmutable($start, $utc); $day <= $today; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $previous = $history[$date] ?? $previous;
            $provisional = $date > $published;
            // All HTTP and parsing have completed; only changed rows enter short writes.
            $count += $rates->saveDay($date, $previous, $provisional);
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
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
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
