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

        return self::transactions($rows, $mapping, $currency);
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
                if ([] !== $rows && \count($row) !== \count($rows[0])) {
                    throw new ImportException('CSV rows must have consistent comma-separated columns.');
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
     * @param non-empty-list<list<string>>                                                                                       $rows
     * @param array{date: int, description: int, debit: int, credit: int, currency: ?int, hasHeader: bool, numberLocale: string} $mapping
     *
     * @return list<ParsedTransactionDTO>
     */
    private static function transactions(#[\SensitiveParameter] array $rows, array $mapping, string $currency): array
    {
        if ($mapping['hasHeader']) {
            unset($rows[0]);
        }
        if ([] === $rows) {
            throw new ImportException('The CSV has no transaction rows.');
        }
        $formatter = new \NumberFormatter($mapping['numberLocale'], \NumberFormatter::DECIMAL);
        $grouping = 'fr_FR' === $mapping['numberLocale'] ? [' ', "\u{00A0}", "\u{202F}"] : [(string) $formatter->getSymbol(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL)];
        $parsed = [];
        foreach ($rows as $index => $row) {
            $date = date_parse($row[$mapping['date']]);
            if ($date['error_count'] > 0 || $date['warning_count'] > 0 || false === $date['year'] || false === $date['month'] || false === $date['day'] || isset($date['relative'])) {
                throw new ImportException('Expected a valid absolute date or datetime in CSV record '.($index + 1).'.');
            }
            $debit = $row[$mapping['debit']];
            $credit = $row[$mapping['credit']];
            $description = $row[$mapping['description']];
            if (null !== $mapping['currency'] && $row[$mapping['currency']] !== $currency) {
                throw new ImportException('CSV currency must match the selected source in record '.($index + 1).'.');
            }
            if ('' === $description || ('' === $debit) === ('' === $credit)) {
                throw new ImportException('Invalid description or debit/credit values in CSV record '.($index + 1).'.');
            }
            $amount = '' !== $debit ? $debit : $credit;
            $position = 0;
            if (false === $formatter->parse($amount, \NumberFormatter::TYPE_DOUBLE, $position) || $position !== mb_strlen($amount)) {
                throw new ImportException('Invalid amount for the detected number format in CSV record '.($index + 1).'.');
            }
            // Normalize the original text, not ICU's floating-point result.
            $amount = str_replace($grouping, '', $amount);
            $amount = str_replace((string) $formatter->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL), '.', $amount);
            if (1 !== preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/D', $amount)) {
                throw new ImportException('Expected an unsigned decimal amount in CSV record '.($index + 1).'.');
            }
            $parsed[] = new ParsedTransactionDTO($index + 1, new \DateTimeImmutable($row[$mapping['date']]), $description, $amount, $currency, '' !== $debit ? Direction::Debit : Direction::Credit);
        }

        return $parsed;
    }
}
