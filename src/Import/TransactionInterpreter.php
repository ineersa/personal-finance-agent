<?php

declare(strict_types=1);

namespace App\Import;

use App\Enum\InstrumentKind;
use App\Enum\RecurrenceKind;
use App\Enum\TransactionMeaning;
use Symfony\AI\Platform\Bridge\Decision\Question\ChoiceQuestion;
use Symfony\AI\Platform\Bridge\Decision\Question\NoulQuestion;

final readonly class TransactionInterpreter
{
    public function __construct(private JevDecisions $jev)
    {
    }

    /**
     * @param list<ParsedTransaction> $transactions Only rows from one authorized source
     * @param array<string, string>   $categories   Shared category names and descriptions
     *
     * @return list<ParsedTransaction>
     */
    public function interpret(#[\SensitiveParameter] array $transactions, InstrumentKind $instrument, array $categories): array
    {
        if ([] === $categories) {
            throw new ImportException('Configure the shared category catalog before processing statements.');
        }
        $meanings = [
            'expense' => 'A purchase, bill, fee or loan payment; excludes transfers between own accounts and credit-card balance payments.',
            'income' => 'Earned or received income such as wages or interest; excludes refunds, own-account transfers and card balance payments.',
            'refund' => 'A reversal or return of a previous purchase or charge; not salary or an account transfer.',
            'own_account_transfer' => 'Money explicitly moved between accounts belonging to the same owner; not an unrelated incoming payment or credit-card balance settlement.',
            'credit_card_payment' => 'Either side of a payment settling the owner’s credit-card balance. On a credit-card statement, a payment received from a bank account settles this card debt, not an ordinary transfer between bank accounts. Excludes new purchases, salary and merchant refunds.',
        ];
        $recurrences = [
            'recurring_bill' => 'A periodic service, rent, utility, fee or loan bill; amounts may vary. Excludes subscription/membership products.',
            'subscription' => 'A recurring subscription or membership, monthly or annual, such as streaming, software or gym membership.',
            'other_recurring' => 'Clearly periodic activity other than a bill or subscription, such as payroll or a scheduled contribution.',
            'non_recurring' => 'An ordinary purchase or isolated transaction with no recurring contract or scheduled payment evidence. Repeated grocery/cafe visits alone are not recurring bills.',
        ];
        $result = [];
        foreach (array_chunk($transactions, 8) as $chunk) {
            $state = [];
            $questions = [];
            foreach ($chunk as $index => $transaction) {
                $id = 'transaction_'.$index;
                $state[$id] = ['date' => $transaction->date->format('Y-m-d'), 'description' => mb_substr($transaction->description, 0, 512), 'amount' => $transaction->amount, 'currency' => $transaction->currency, 'direction' => $transaction->direction->value];
                foreach (['category' => $categories, 'meaning' => $meanings, 'recurrence' => $recurrences] as $dimension => $options) {
                    $questions[$id.'_'.$dimension] = new ChoiceQuestion('Which '.$dimension.' describes `transactions.'.$id.'`?', $options);
                    $questions[$id.'_'.$dimension.'_supported'] = new NoulQuestion('Does `transactions.'.$id.'` provide enough evidence to select a specific '.$dimension.' from these options?', 'The description or bounded same-source history supports a specific option.', 'The record is an opaque reference, ambiguous, or does not establish which option applies.');
                }
            }
            $answers = $this->jev->evaluate(['source_context' => ['instrument_kind' => $instrument->value, 'statement_type' => InstrumentKind::CreditCard === $instrument ? 'Credit-card statement: charges increase debt; received account payments reduce this credit-card debt.' : 'Bank-account statement: debit is money leaving this account; credit is money arriving.'], 'transactions' => $state, 'category_options' => $categories, 'meaning_options' => $meanings, 'recurrence_options' => $recurrences], $questions);
            foreach ($chunk as $index => $transaction) {
                $id = 'transaction_'.$index;
                $category = $answers->getNoul($id.'_category_supported')->isTrue(0.7) ? $answers->getChoice($id.'_category')->getChoice() : null;
                if (null !== $category && !\array_key_exists($category, $categories)) {
                    throw new ImportException('Jev selected a category outside the shared catalog.');
                }
                $meaning = $answers->getNoul($id.'_meaning_supported')->isTrue(0.7) ? TransactionMeaning::tryFrom($answers->getChoice($id.'_meaning')->getChoice()) : TransactionMeaning::Unknown;
                $recurrence = $answers->getNoul($id.'_recurrence_supported')->isTrue(0.7) ? RecurrenceKind::tryFrom($answers->getChoice($id.'_recurrence')->getChoice()) : RecurrenceKind::Unknown;
                if (null === $meaning || null === $recurrence) {
                    throw new ImportException('Jev selected an unsupported transaction interpretation.');
                }
                $result[] = new ParsedTransaction($transaction->rowNumber, $transaction->date, $transaction->description, $transaction->amount, $transaction->currency, $transaction->direction, $category, $meaning, $recurrence);
            }
        }

        return $result;
    }
}
