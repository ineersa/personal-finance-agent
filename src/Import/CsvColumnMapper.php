<?php

declare(strict_types=1);

namespace App\Import;

use App\Enum\InstrumentKind;
use Symfony\AI\Platform\Bridge\Decision\Question\ChoiceQuestion;
use Symfony\AI\Platform\Bridge\Decision\Question\NoulQuestion;

final readonly class CsvColumnMapper
{
    public function __construct(private JevDecisions $jev)
    {
    }

    /**
     * @param non-empty-list<list<string>> $rows
     *
     * @return array{date: int, description: int, debit: int, credit: int, currency: ?int, hasHeader: bool, numberLocale: string}
     */
    public function map(#[\SensitiveParameter] array $rows, string $currency, InstrumentKind $instrument): array
    {
        $samples = $this->sampleRows($rows);
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
        $questions['number_format'] = new ChoiceQuestion('Which number format matches the monetary amounts in the CSV sample rows?', [
            'en_US' => 'Decimal point, comma grouping: 1,234.56 or 1234.56; 12.34.',
            'de_DE' => 'Decimal comma, point grouping: 1.234,56 or 1234,56; 12,34.',
            'fr_FR' => 'Decimal comma, space grouping (including non-breaking spaces): 1 234,56 or 1234,56; 12,34.',
        ]);
        $questions['number_format_resolved'] = new NoulQuestion('Do the monetary amounts establish a supported number convention without ambiguity about their magnitude?', 'The amounts establish decimal/grouping usage: 1,234.56, 1.234,56, or 1 234,56 (including non-breaking spaces); unseparated integers also work. Absent grouping does not need to be inferred.', 'Separators leave the monetary magnitude ambiguous (for example only 1,234), or the amounts mix incompatible or unsupported conventions.');
        $answers = $this->jev->evaluate(['source_currency' => $currency, 'instrument' => $instrument->value, 'rows' => $samples], $questions);
        if (!$answers->getNoul('number_format_resolved')->isTrue(0.7)) {
            throw new ImportException('The CSV number format is unresolved. Export amounts with unambiguous decimal and grouping separators.');
        }
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

        return ['date' => $mapping['date'], 'description' => $mapping['description'], 'debit' => $mapping['debit'], 'credit' => $mapping['credit'], 'currency' => $currencyColumn, 'hasHeader' => $header >= 0.7, 'numberLocale' => $answers->getChoice('number_format')->getChoice()];
    }

    /**
     * @param non-empty-list<list<string>> $rows
     *
     * @return list<array<string, string>>
     */
    private function sampleRows(#[\SensitiveParameter] array $rows): array
    {
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

        return $samples;
    }
}
