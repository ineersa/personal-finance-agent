<?php

declare(strict_types=1);

namespace AppTasks;

use Castor\Attribute\AsTask;

#[AsTask(namespace: 'demo', name: 'statements:inspect', description: 'Paid Jev diagnostic using only the three committed synthetic holdouts; no database writes.')]
function demoStatementsInspect(): int
{
    return report('demo:statements:inspect', ['php', 'bin/console', 'app:demo:inspect-statements', '--no-interaction', '--no-debug'], jev: true);
}

#[AsTask(namespace: 'demo', name: 'statements:generate', description: 'Regenerate invented monthly CSV inputs and expected outcomes; never accesses a database or provider.')]
function demoStatementsGenerate(): int
{
    return report('demo:statements:generate', [], runtime: false, operation: static function (): int {
        $root = \dirname(__DIR__).'/resources/demo-statements';
        $manifest = ['status' => 'synthetic expectations, not Jev results', 'first_month' => '2024-11', 'last_month' => '2026-10', 'holdout_month' => '2026-10', 'statements' => []];
        $start = new \DateTimeImmutable('2024-11-01');
        for ($month = 0; $month < 24; ++$month) {
            $date = $start->modify('+'.$month.' months');
            foreach (['chequing', 'credit', 'usd'] as $source) {
                $rows = demoMonthlyRows($source, $month, $date->format('Y-m'));
                $relative = $source.'/'.$date->format('Y-m').'.csv';
                $directory = $root.'/'.$source;
                if (!is_dir($directory)) {
                    mkdir($directory, 0755, true);
                }
                $file = fopen($root.'/'.$relative, 'wb');
                if (false === $file) {
                    throw new \RuntimeException('Cannot write synthetic statement.');
                }
                try {
                    foreach ($rows as $row) {
                        $csv = [$row['date'], $row['description'], 'debit' === $row['direction'] ? $row['amount'] : '', 'credit' === $row['direction'] ? $row['amount'] : ''];
                        if ('credit' === $source) {
                            $csv[] = '0000********4826';
                        }
                        fputcsv($file, $csv, ',', '"', '', "\n");
                    }
                } finally {
                    fclose($file);
                }
                $totals = ['spending_debits' => 0, 'refund_credits' => 0, 'income_credits' => 0, 'unresolved_rows' => 0];
                foreach ($rows as $row) {
                    $cents = (int) str_replace('.', '', $row['amount']);
                    if ('expense' === $row['meaning'] && 'debit' === $row['direction']) {
                        $totals['spending_debits'] += $cents;
                    } elseif ('refund' === $row['meaning'] && 'credit' === $row['direction']) {
                        $totals['refund_credits'] += $cents;
                    } elseif ('income' === $row['meaning'] && 'credit' === $row['direction']) {
                        $totals['income_credits'] += $cents;
                    } elseif ('unknown' === $row['meaning']) {
                        ++$totals['unresolved_rows'];
                    }
                }
                $expectedTotals = [];
                foreach (['spending_debits', 'refund_credits', 'income_credits'] as $key) {
                    $expectedTotals[$key] = intdiv($totals[$key], 100).'.'.str_pad((string) ($totals[$key] % 100), 2, '0', \STR_PAD_LEFT);
                }
                $expectedTotals['unresolved_rows'] = $totals['unresolved_rows'];
                $manifest['statements'][] = ['file' => $relative, 'source_fixture' => ['chequing' => 'demo_current', 'credit' => 'demo_card', 'usd' => 'demo_usd'][$source], 'currency' => 'usd' === $source ? 'USD' : 'CAD', 'purpose' => 23 === $month ? 'upload_holdout' : 'history_pending_pipeline', 'expected_totals' => $expectedTotals, 'rows' => $rows];
            }
        }
        file_put_contents($root.'/expected.json', json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n");
        echo "Generated 72 synthetic statements; 69 history candidates and 3 upload holdouts. No financial records imported.\n";

        return 0;
    });
}

/** @return list<array{date: string, description: string, amount: string, direction: string, category: string, meaning: string, recurrence: string}> */
function demoMonthlyRows(string $source, int $month, string $period): array
{
    $rows = [];
    $add = static function (int $day, string $description, int $cents, string $direction, string $category, string $meaning = 'expense', string $recurrence = 'non_recurring') use (&$rows, $period): void {
        $rows[] = ['date' => $period.'-'.str_pad((string) $day, 2, '0', \STR_PAD_LEFT), 'description' => $description, 'amount' => intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', \STR_PAD_LEFT), 'direction' => $direction, 'category' => $category, 'meaning' => $meaning, 'recurrence' => $recurrence];
    };
    // Deterministic invented variation, independent of personal statements and global RNG state.
    $variation = static fn (int $salt, int $range): int => (int) hexdec(substr(hash('sha256', $source.':'.$month.':'.$salt), 0, 6)) % $range;
    $cardPayment = 88000 + (int) hexdec(substr(hash('sha256', 'card-payment:'.$month), 0, 6)) % 45000;
    if ('chequing' === $source) {
        $add(1, 'PAYROLL LANTERN LABS', 286000 + intdiv($month, 6) * 8500, 'credit', 'salary', 'income', 'other_recurring');
        $add(2, 'RENT WILLOW COURT monthly', 148000, 'debit', 'housing', 'expense', 'recurring_bill');
        $add(5, 'CEDARLINE INTERNET monthly service', 7295, 'debit', 'internet_phone', 'expense', 'recurring_bill');
        $add(8, 'RIVERLIGHT ELECTRIC monthly bill', 6500 + $variation(1, 8500), 'debit', 'utilities', 'expense', 'recurring_bill');
        $add(12, 'PAYMENT TO OWN DEMO CREDIT CARD', $cardPayment, 'debit', 'card_payments', 'credit_card_payment', 'other_recurring');
        $add(15, 'PAYROLL LANTERN LABS', 286000 + intdiv($month, 6) * 8500, 'credit', 'salary', 'income', 'other_recurring');
        $add(18, 'TRANSFER TO OWN DEMO USD ACCOUNT', 40000 + $variation(3, 8000), 'debit', 'transfers', 'own_account_transfer');
        $add(21, 'LOAN PAYMENT monthly', 18500, 'debit', 'loans', 'expense', 'recurring_bill');
        $add(24, 'INVESTMENT CONTRIBUTION monthly', 27500, 'debit', 'savings', 'own_account_transfer', 'other_recurring');
        $add(27, 'MONTHLY ACCOUNT FEE', 1195, 'debit', 'fees', 'expense', 'recurring_bill');
        $add(28, 'INTEREST PAID', 35 + $variation(4, 90), 'credit', 'other_income', 'income', 'other_recurring');
    } elseif ('credit' === $source) {
        foreach ([3, 10, 17, 24] as $day) {
            $add($day, 'PINE BASKET GROCERY', 5500 + $variation($day, 10000), 'debit', 'groceries');
            $add($day + 1, 'EMBER CAFE', 900 + $variation($day + 1, 1800), 'debit', 'dining');
        }
        $add(5, 'MELODY CLOUD monthly music subscription', 1299, 'debit', 'subscriptions', 'expense', 'subscription');
        $add(6, 'NORTHPATH TRANSIT PASS monthly', 10400, 'debit', 'transportation', 'expense', 'recurring_bill');
        $add(7, 'GREENLEAF PHARMACY', 1800 + $variation(8, 6000), 'debit', 'health');
        $add(9, 'CLOUDHARBOR MOBILE monthly plan', 4500, 'debit', 'internet_phone', 'expense', 'recurring_bill');
        $add(12, 'PAYMENT RECEIVED FROM OWN DEMO CHEQUING', $cardPayment, 'credit', 'card_payments', 'credit_card_payment', 'other_recurring');
        $add(14, 'OAK TRAIL OUTFITTERS jacket', 6800 + $variation(10, 11000), 'debit', 'shopping');
        $add(19, 'MOSS COMMUNITY THEATRE', 2400 + $variation(11, 3000), 'debit', 'entertainment');
        $add(20, 'BRIGHT PAGES evening course', 6500 + $variation(12, 5000), 'debit', 'education');
        $add(22, 'OPEN HANDS CHARITY donation', 2500, 'debit', 'gifts');
        if (0 === $month % 3) {
            $add(26, 'REFUND OAK TRAIL OUTFITTERS returned jacket', 6800 + $variation(10, 11000), 'credit', 'shopping', 'refund');
        }
        if (0 === $month % 12) {
            $add(28, 'CLOVER FITNESS annual membership', 18900, 'debit', 'subscriptions', 'expense', 'subscription');
        }
    } else {
        $add(2, 'TRANSFER FROM OWN DEMO CAD CHEQUING', 29500 + $variation(1, 4000), 'credit', 'transfers', 'own_account_transfer');
        $add(8, 'CIRRUS NOTES monthly software subscription', 850, 'debit', 'subscriptions', 'expense', 'subscription');
        $add(27, 'MONTHLY USD ACCOUNT FEE', 395, 'debit', 'fees', 'expense', 'recurring_bill');
        if (0 === $month % 4) {
            $add(14, 'HARBOR LANTERN HOTEL weekend trip', 19500 + $variation(2, 12000), 'debit', 'travel');
            $add(16, 'HARBOR LANTERN HOTEL deposit refund', 2500, 'credit', 'travel', 'refund');
        }
        $add(28, 'REF 907462', 1700 + $variation(3, 4000), 'credit', 'uncategorized', 'unknown', 'unknown');
    }
    usort($rows, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

    return $rows;
}
