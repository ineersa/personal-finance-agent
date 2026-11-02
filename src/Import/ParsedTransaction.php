<?php

declare(strict_types=1);

namespace App\Import;

use App\Enum\Direction;
use App\Enum\RecurrenceKind;
use App\Enum\TransactionMeaning;

final readonly class ParsedTransaction
{
    public function __construct(
        public int $rowNumber,
        public \DateTimeImmutable $date,
        public string $description,
        public string $amount,
        public string $currency,
        public Direction $direction,
        public ?string $categoryName = null,
        public TransactionMeaning $meaning = TransactionMeaning::Unknown,
        public RecurrenceKind $recurrence = RecurrenceKind::Unknown,
    ) {
    }
}
