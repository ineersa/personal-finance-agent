<?php

declare(strict_types=1);

namespace App\Import;

use App\Enum\InstrumentKind;

final readonly class CsvParser
{
    public function __construct(private CsvRecordReader $reader, private CsvColumnMapper $mapper, private CsvTransactionParser $transactions)
    {
    }

    /** @return list<ParsedTransactionDTO> */
    public function parse(#[\SensitiveParameter] string $content, string $currency, InstrumentKind $instrument): array
    {
        if (1 !== preg_match('/\A[A-Z]{3}\z/D', $currency)) {
            throw new ImportException('Select a source with an explicit three-letter currency before parsing.');
        }
        $rows = $this->reader->read($content);
        $mapping = $this->mapper->map($rows, $currency, $instrument);

        return $this->transactions->parse($rows, $mapping, $currency);
    }
}
