<?php

declare(strict_types=1);

namespace App\Import;

final readonly class CsvColumnMappingDTO
{
    public function __construct(public int $date, public int $description, public int $debit, public int $credit, public ?int $currency, public bool $hasHeader)
    {
    }
}
