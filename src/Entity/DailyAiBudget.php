<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class DailyAiBudget
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'date_immutable')]
        public \DateTimeImmutable $day,
        #[ORM\Column]
        public int $reservedTokens = 0,
        #[ORM\Column]
        public int $consumedTokens = 0,
        #[ORM\Column(type: 'exact_decimal')]
        public string $reservedCost = '0',
        #[ORM\Column(type: 'exact_decimal')]
        public string $consumedCost = '0',
    ) {
    }
}
