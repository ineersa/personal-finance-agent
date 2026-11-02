<?php

declare(strict_types=1);

namespace App\Import;

use App\Enum\Direction;
use App\Enum\InstrumentKind;
use Symfony\AI\Platform\Bridge\Decision\Question\ChoiceQuestion;
use Symfony\AI\Platform\Bridge\Decision\Question\NoulQuestion;

final readonly class CsvParser
{
    public function __construct(private JevDecisions $jev)
    {
    }

    /** @return list<ParsedTransaction> */
    public function parse(#[\SensitiveParameter] string $content, string $currency, InstrumentKind $instrument): array
    {
        if ('' === $content || \strlen($content) > 10 * 1024 * 1024 || 1 !== preg_match('//u', $content) || str_contains($content, "\0")) {
            throw new ImportException('Supply a non-empty UTF-8 CSV of at most 10 MiB.');
        }
        if (1 !== preg_match('/\A[A-Z]{3}\z/D', $currency)) {
            throw new ImportException('Select a source with an explicit three-letter currency before parsing.');
        }
        $stream = fopen('php://temp', 'w+b');
        if (false === $stream) {
            throw new ImportException('Cannot read CSV input.');
        }
        $rows = [];
        try {
            fwrite($stream, preg_replace('/\A\xEF\xBB\xBF/', '', $content) ?? $content);
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
        $samples = [];
        // Preserve rare credit/debit occupancy patterns before filling evenly spaced samples.
        $indices = [0];
        $patterns = [];
        foreach ($rows as $index => $row) {
            $pattern = implode('', array_map(static fn (string $cell): string => '' === $cell ? '0' : '1', $row));
            if (!isset($patterns[$pattern])) {
                $patterns[$pattern] = true;
                $indices[] = $index;
            }
        }
        $indices = \array_slice(array_values(array_unique([...$indices, ...array_map(static fn (int $i): int => (int) floor($i * (\count($rows) - 1) / 7), range(0, 7))])), 0, 8);
        foreach ($indices as $index) {
            $sample = [];
            foreach ($rows[$index] as $column => $value) {
                $sample['column_'.$column] = mb_substr($value, 0, 512);
            }
            $samples[] = $sample;
        }
        $columns = [];
        foreach (array_keys($rows[0]) as $column) {
            $columns['column_'.$column] = 'The values under column_'.$column.' across complete sample rows.';
        }
        $definitions = [
            'date' => 'The date on which this statement records a transaction, not a statement period boundary.',
            'description' => 'Merchant, payee or transaction narrative, not an account/card identifier.',
            'debit' => 'Debit amounts: purchases, fees and outgoing transfers from a bank account, or charges increasing credit-card debt.',
            'credit' => 'Credit amounts: incoming bank-account money including transfers from the owner’s other accounts, payroll and refunds, or payments/refunds reducing credit-card debt. A credit need not be income.',
        ];
        $questions = [];
        foreach ($definitions as $role => $definition) {
            $questions[$role] = new ChoiceQuestion('Which column contains '.$definition, $columns);
            $questions[$role.'_present'] = new NoulQuestion('Do the CSV sample rows contain a column for '.$definition, 'A CSV column has this meaning.', 'No CSV column has this meaning or the evidence does not establish it.');
        }
        $questions['header'] = new NoulQuestion('Is the first sample row a header describing columns rather than an actual transaction?', 'The first row contains field labels.', 'The first row is a transaction with date and values.');
        $questions['currency_present'] = new NoulQuestion('Is there a dedicated CSV currency column containing currency codes or names?', 'A separate column explicitly specifies each transaction currency.', 'Currency is absent from CSV columns; account context or mention in a transaction description does not count.');
        $questions['currency'] = new ChoiceQuestion('Which column explicitly specifies transaction currency?', $columns);
        $answers = $this->jev->evaluate(['source_currency' => $currency, 'instrument' => $instrument->value, 'rows' => $samples], $questions);
        $mapping = [];
        foreach (array_keys($definitions) as $role) {
            if (!$answers->getNoul($role.'_present')->isTrue(0.7)) {
                throw new ImportException('CSV column meaning is unresolved for '.$role.' (support '.$answers->getNoul($role.'_present')->getProbability().'). A date, description, debit and credit column are required.');
            }
            $choice = $answers->getChoice($role)->getChoice();
            $column = array_search($choice, array_keys($columns), true);
            if (false === $column || \in_array($column, $mapping, true)) {
                throw new ImportException('CSV column mapping is missing or contradictory.');
            }
            $mapping[$role] = $column;
        }
        $currencyColumn = null;
        $currencySupport = $answers->getNoul('currency_present')->getProbability();
        if ($currencySupport > 0.3 && $currencySupport < 0.7) {
            throw new ImportException('It is unclear whether the CSV specifies a separate currency.');
        }
        if ($currencySupport >= 0.7) {
            $currencyColumn = array_search($answers->getChoice('currency')->getChoice(), array_keys($columns), true);
            if (false === $currencyColumn || \in_array($currencyColumn, $mapping, true)) {
                throw new ImportException('The CSV currency mapping is contradictory.');
            }
        }
        $header = $answers->getNoul('header')->getProbability();
        if ($header > 0.3 && $header < 0.7) {
            throw new ImportException('It is unclear whether the CSV contains a header.');
        }
        $parsed = [];
        foreach ($rows as $index => $row) {
            if (0 === $index && $header >= 0.7) {
                continue;
            }
            $rawDate = $row[$mapping['date']];
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);
            $debit = $row[$mapping['debit']];
            $credit = $row[$mapping['credit']];
            $description = $row[$mapping['description']];
            if (null !== $currencyColumn && $row[$currencyColumn] !== $currency) {
                throw new ImportException('CSV currency must match the selected source in record '.($index + 1).'.');
            }
            if (false === $date || $date->format('Y-m-d') !== $rawDate || '' === $description || ('' === $debit) === ('' === $credit)) {
                throw new ImportException('Invalid date, description or debit/credit values in CSV record '.($index + 1).'.');
            }
            $amount = '' !== $debit ? $debit : $credit;
            if (1 !== preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/D', $amount)) {
                throw new ImportException('Expected an unsigned decimal amount in CSV record '.($index + 1).'.');
            }
            $parsed[] = new ParsedTransaction($index + 1, $date, $description, $amount, $currency, '' !== $debit ? Direction::Debit : Direction::Credit);
        }
        if ([] === $parsed) {
            throw new ImportException('The CSV has no transaction rows.');
        }

        return $parsed;
    }
}
