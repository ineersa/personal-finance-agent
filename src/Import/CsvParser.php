<?php

declare(strict_types=1);

namespace App\Import;

use App\Enum\Direction;
use App\Enum\InstrumentKind;

final readonly class CsvParser
{
    public function __construct(private CsvColumnMapper $mapper)
    {
    }

    /** @return list<ParsedTransactionDTO> */
    public function parse(#[\SensitiveParameter] string $content, string $currency, InstrumentKind $instrument): array
    {
        if (1 !== preg_match('/\A[A-Z]{3}\z/D', $currency)) {
            throw new ImportException('Select a source with an explicit three-letter currency before parsing.');
        }
        $rows = $this->read($content);
        $mapping = $this->mapper->map($rows, $currency, $instrument);

        return $this->transactions($rows, $mapping, $currency);
    }

    /** @return non-empty-list<list<string>> */
    private function read(#[\SensitiveParameter] string $content): array
    {
        if ('' === $content || \strlen($content) > 10 * 1024 * 1024 || 1 !== preg_match('//u', $content) || str_contains($content, "\0")) {
            throw new ImportException('Supply a non-empty UTF-8 CSV of at most 10 MiB.');
        }
        $content = preg_replace('/\A\xEF\xBB\xBF/', '', $content) ?? $content;
        $this->validateEnclosures($content);
        $stream = fopen('php://temp', 'w+b');
        if (false === $stream) {
            throw new ImportException('Cannot read CSV input.');
        }
        $rows = [];
        try {
            fwrite($stream, $content);
            rewind($stream);
            while (false !== ($row = fgetcsv($stream, escape: ''))) {
                if ([null] === $row) {
                    continue;
                }
                $row = array_map(static fn (?string $cell): string => trim($cell ?? ''), $row);
                if (\count($row) < 4 || \count($row) > 12 || ([] !== $rows && \count($row) !== \count($rows[0]))) {
                    throw new ImportException('CSV rows must have consistent comma-separated columns (4–12 fields).');
                }
                $rows[] = $row;
            }
        } finally {
            fclose($stream);
        }
        if ([] === $rows) {
            throw new ImportException('The CSV has no transaction rows.');
        }

        return $rows;
    }

    // PHP accepts an unterminated enclosure at EOF. Validate it before native CSV decoding.
    private function validateEnclosures(#[\SensitiveParameter] string $content): void
    {
        $quoted = false;
        $fieldStart = true;
        $length = \strlen($content);
        for ($index = 0; $index < $length; ++$index) {
            $character = $content[$index];
            if ($quoted) {
                if ('"' === $character) {
                    if ($index + 1 < $length && '"' === $content[$index + 1]) {
                        ++$index;
                    } else {
                        $quoted = false;
                    }
                }
                continue;
            }
            if (',' === $character || "\n" === $character || "\r" === $character) {
                $fieldStart = true;
                continue;
            }
            if ($fieldStart && \in_array($character, [' ', "\t", "\v", "\f"], true)) {
                continue;
            }
            if ($fieldStart && '"' === $character) {
                $quoted = true;
            }
            $fieldStart = false;
        }
        if ($quoted) {
            throw new ImportException('The CSV contains an unterminated quoted field.');
        }
    }

    /**
     * @param non-empty-list<list<string>>                                                                 $rows
     * @param array{date: int, description: int, debit: int, credit: int, currency: ?int, hasHeader: bool} $mapping
     *
     * @return list<ParsedTransactionDTO>
     */
    private function transactions(#[\SensitiveParameter] array $rows, array $mapping, string $currency): array
    {
        $parsed = [];
        foreach ($rows as $index => $row) {
            if (0 === $index && $mapping['hasHeader']) {
                continue;
            }
            $rawDate = $row[$mapping['date']];
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);
            $debit = $row[$mapping['debit']];
            $credit = $row[$mapping['credit']];
            $description = $row[$mapping['description']];
            if (null !== $mapping['currency'] && $row[$mapping['currency']] !== $currency) {
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
