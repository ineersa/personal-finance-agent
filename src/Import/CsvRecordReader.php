<?php

declare(strict_types=1);

namespace App\Import;

final readonly class CsvRecordReader
{
    /** @return non-empty-list<list<string>> */
    public function read(#[\SensitiveParameter] string $content): array
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
        $closed = false;
        $length = \strlen($content);
        for ($index = 0; $index < $length; ++$index) {
            $character = $content[$index];
            if ($quoted) {
                if ('"' === $character) {
                    if ($index + 1 < $length && '"' === $content[$index + 1]) {
                        ++$index;
                    } else {
                        $quoted = false;
                        $closed = true;
                    }
                }
                continue;
            }
            if (',' === $character || "\n" === $character || "\r" === $character) {
                $fieldStart = true;
                $closed = false;
                continue;
            }
            if (($fieldStart || $closed) && \in_array($character, [' ', "\t", "\v", "\f"], true)) {
                continue;
            }
            if ($closed) {
                throw new ImportException('The CSV contains unexpected content after a quoted field.');
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
}
