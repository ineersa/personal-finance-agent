<?php

declare(strict_types=1);

namespace App\Finance;

use App\Enum\ReportingCurrency;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class EcbRateImporter
{
    public function __construct(private HttpClientInterface $client, private Connection $connection)
    {
    }

    public function import(): int
    {
        $xml = $this->client->request('GET', 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist.xml')->getContent();
        $document = new \DOMDocument();
        if (!@$document->loadXML($xml, \LIBXML_NONET)) {
            throw new \RuntimeException('Invalid ECB reference-rate XML.');
        }
        $history = [];
        foreach ($document->getElementsByTagName('Cube') as $cube) {
            if (!$cube->hasAttribute('time')) {
                continue;
            }
            $day = $cube->getAttribute('time');
            if (1 !== preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $day)) {
                throw new \RuntimeException('Invalid ECB reference date.');
            }
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
                $history[$day][$currency] = $value;
            }
        }
        if ([] === $history) {
            throw new \RuntimeException('ECB reference-rate history is empty.');
        }
        ksort($history);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $start = $today->modify('-3 years');
        $previous = [];
        foreach ($history as $day => $rates) {
            if ($day < $start->format('Y-m-d')) {
                $previous = array_replace($previous, $rates);
            }
        }
        $count = 0;
        for ($day = $start; $day <= $today; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $previous = array_replace($previous, $history[$date] ?? []);
            // One short write transaction per calendar day, after downloading and parsing.
            $this->connection->transactional(static function (Connection $connection) use ($date, $previous): void {
                foreach ($previous as $currency => $rate) {
                    $connection->executeStatement('INSERT INTO currency_rate (day, currency, units_per_eur) VALUES (?, ?, ?) ON CONFLICT (day, currency) DO UPDATE SET units_per_eur = excluded.units_per_eur', [$date, $currency, $rate]);
                }
            });
            $count += \count($previous);
        }

        return $count;
    }
}
