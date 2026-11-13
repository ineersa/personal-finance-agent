<?php

declare(strict_types=1);

namespace App\Finance;

use App\Entity\User;
use App\Enum\ReportingCurrency;
use App\Repository\CurrencyRateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class EcbRateRefresher
{
    public function __construct(#[Autowire(service: 'app.ecb.http_client')] private HttpClientInterface $client, private EntityManagerInterface $central, private TenantManagers $tenants, private LoggerInterface $logger)
    {
    }

    public function refresh(): int
    {
        $daily = $this->fetch('eurofxref-daily.xml');
        $history = null;
        $count = 0;
        $failed = 0;
        foreach ($this->central->getRepository(User::class)->findAll() as $owner) {
            if (\in_array('ROLE_ADMIN', $owner->getRoles(), true)) {
                continue;
            }
            try {
                $em = null;
                try {
                    $em = $this->tenants->open($owner);
                    $count += $this->refreshTenant(new CurrencyRateRepository($em), $daily, $history);
                } finally {
                    if (null !== $em) {
                        $this->tenants->release($em);
                    }
                }
            } catch (\Throwable) {
                ++$failed;
                $this->logger->warning('Tenant currency refresh failed.', ['owner_id' => $owner->getId()]);
            }
        }
        if (0 < $failed) {
            throw new \RuntimeException(\sprintf('Currency refresh failed for %d tenants; updated %d calendar-day currency rates in completed writes.', $failed, $count));
        }

        return $count;
    }

    /** @param non-empty-array<string, non-empty-array<string, string>> $daily
     * @param non-empty-array<string, non-empty-array<string, string>>|\Throwable|null $cachedHistory
     */
    private function refreshTenant(CurrencyRateRepository $rates, array $daily, array|\Throwable|null &$cachedHistory): int
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
            try {
                $cachedHistory ??= $this->fetch('eurofxref-hist-90d.xml');
            } catch (\Throwable $error) {
                $cachedHistory = $error;
            }
            if ($cachedHistory instanceof \Throwable) {
                throw new \RuntimeException('ECB recent history is unavailable.');
            }
            $history = $cachedHistory;
            if (array_key_last($history) !== $published) {
                throw new \RuntimeException('ECB recent history has not caught up with the daily publication.');
            }
            if (\is_string($firstStored) && array_key_first($history) > $firstMissing) {
                throw new \RuntimeException('ECB recent history does not cover the missing date. Restore canonical historical fixtures.');
            }
            foreach ($history as $dayRates) {
                if (array_keys($dayRates) !== $currencies) {
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
        foreach ($history as $date => $dayRates) {
            if ($date < $start) {
                $previous = $dayRates;
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
