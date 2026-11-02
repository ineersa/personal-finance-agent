<?php

declare(strict_types=1);

use App\Entity\CurrencyRate;

// Native Alice PHP fixtures; the same public snapshot is used by dev, reset and browsers.
$history = json_decode(file_get_contents(__DIR__.'/../resources/ecb/reference-rates.json'), true, flags: JSON_THROW_ON_ERROR);
$first = new DateTimeImmutable(array_key_first($history));
$last = new DateTimeImmutable(array_key_last($history));
$previous = [];
$fixtures = [];
for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
    $date = $day->format('Y-m-d');
    $previous = $history[$date] ?? $previous;
    foreach ($previous as $currency => $rate) {
        $fixtures['ecb_'.$date.'_'.$currency] = ['__construct' => ['<(new \\DateTimeImmutable("'.$date.'"))>', $currency, $rate]];
    }
}

return [CurrencyRate::class => $fixtures];
