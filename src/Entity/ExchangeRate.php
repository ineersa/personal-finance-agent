<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ExchangeRate
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'date_immutable')]
        public \DateTimeImmutable $day,
        #[ORM\Id]
        #[ORM\Column(length: 3)]
        public string $currency,
        #[ORM\Column(type: 'exact_decimal')]
        public string $unitsPerEur,
    ) {
    }
}
