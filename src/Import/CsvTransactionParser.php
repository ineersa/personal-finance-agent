<?php

declare(strict_types=1);

namespace App\Import;

use App\Enum\Direction;

final readonly class CsvTransactionParser
{
    /**
     * @param non-empty-list<list<string>> $rows
     *
     * @return list<ParsedTransactionDTO>
     */
    public function parse(#[\SensitiveParameter] array $rows, CsvColumnMappingDTO $mapping, string $currency): array
    {
        $parsed = [];
        foreach ($rows as $index => $row) {
            if (0 === $index && $mapping->hasHeader) {
                continue;
            }
            $rawDate = $row[$mapping->date];
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);
            $debit = $row[$mapping->debit];
            $credit = $row[$mapping->credit];
            $description = $row[$mapping->description];
            if (null !== $mapping->currency && $row[$mapping->currency] !== $currency) {
                throw new ImportException('CSV currency must match the selected source in record '.($index + 1).'.');
            }
            if (false === $date || $date->format('Y-m-d') !== $rawDate || '' === $description || ('' === $debit) === ('' === $credit)) {
                throw new ImportException('Invalid date, description or debit/credit values in CSV record '.($index + 1).'.');
            }
            $amount = '' !== $debit ? $debit : $credit;
            if (1 !== preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/D', $amount)) {
                throw new ImportException('Expected an unsigned decimal amount in CSV record '.($index + 1).'.');
            }
            $parsed[] = new ParsedTransactionDTO($index + 1, $date, $description, $amount, $currency, '' !== $debit ? Direction::Debit : Direction::Credit);
        }
        if ([] === $parsed) {
            throw new ImportException('The CSV has no transaction rows.');
        }

        return $parsed;
    }
}
