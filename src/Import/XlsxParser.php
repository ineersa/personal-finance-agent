<?php

declare(strict_types=1);

namespace App\Import;

use App\Enum\InstrumentKind;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Reader;

/** Local worksheet decoding only; mapping and financial parsing remain shared with CSV. */
final readonly class XlsxParser
{
    public function __construct(private CsvParser $parser)
    {
    }

    /** @return list<ParsedTransactionDTO> */
    public function parse(#[\SensitiveParameter] string $content, string $currency, InstrumentKind $instrument): array
    {
        if ('' === $content || \strlen($content) > DocumentConverter::MAX_INPUT_BYTES) {
            throw new ImportException('Supply a non-empty XLSX workbook of at most 10 MiB.');
        }
        $file = tmpfile();
        if (false === $file) {
            throw new ImportException('Cannot prepare private workbook input.');
        }
        $reader = new Reader();
        try {
            if (fwrite($file, $content) !== \strlen($content)) {
                throw new ImportException('Cannot prepare private workbook input.');
            }
            $reader->open(stream_get_meta_data($file)['uri']);
            $transactions = [];
            $bytes = 0;
            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];
                $width = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $values = [];
                    foreach ($row->getCells() as $cell) {
                        if ($cell instanceof FormulaCell || $cell instanceof ErrorCell) {
                            throw new ImportException('Use worksheet values rather than formulas or error cells.');
                        }
                        $value = $cell->getValue();
                        $text = match (true) {
                            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                            \is_string($value) => trim($value),
                            \is_int($value) => (string) $value,
                            // No arithmetic or display-format rounding. Preserve the reader's round-trip decimal.
                            \is_float($value) => json_encode($value, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
                            null === $value => '',
                            default => throw new ImportException('The workbook contains unsupported cell values.'),
                        };
                        if (1 !== preg_match('//u', $text) || str_contains($text, "\0")) {
                            throw new ImportException('The workbook contains invalid cell text.');
                        }
                        $bytes += \strlen($text) + 1;
                        if ($bytes > DocumentConverter::MAX_INPUT_BYTES) {
                            throw new ImportException('The decoded workbook exceeds the 10 MiB tabular input limit.');
                        }
                        $values[] = $text;
                    }
                    if ([] === array_filter($values, static fn (string $value): bool => '' !== $value)) {
                        continue;
                    }
                    $width = max($width, \count($values));
                    $rows[] = $values;
                }
                if ([] === $rows) {
                    continue;
                }
                // XLSX omits empty trailing cells. Give the shared mapper a rectangular table.
                $rows = array_map(static fn (array $row): array => array_pad($row, $width, ''), $rows);
                array_push($transactions, ...$this->parser->parseRows($rows, $currency, $instrument));
            }
            if ([] === $transactions) {
                throw new ImportException('The workbook has no transaction rows.');
            }

            return $transactions;
        } catch (ImportException $error) {
            throw $error;
        } catch (\Throwable) {
            // Reader exceptions may contain private paths/cell contents. Never retain their chains.
            throw new ImportException('The XLSX workbook could not be read. Use a readable tabular worksheet with explicit dates and separate debit/credit columns.');
        } finally {
            try {
                $reader->close();
            } finally {
                fclose($file);
            }
        }
    }
}
