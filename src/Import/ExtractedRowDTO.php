<?php

declare(strict_types=1);

namespace App\Import;

use App\Enum\Direction;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ExtractedRowDTO
{
    public function __construct(
        #[Assert\Date]
        #[Assert\Regex('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D')]
        public string $date,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $description,
        #[Assert\Regex('/\A[0-9]+(?:\.[0-9]+)?\z/D')]
        public string $amount,
        #[Assert\Regex('/\A[A-Z]{3}\z/D')]
        public string $currency,
        #[Assert\Choice(choices: [Direction::Credit->value, Direction::Debit->value])]
        public string $direction,
    ) {
    }
}
