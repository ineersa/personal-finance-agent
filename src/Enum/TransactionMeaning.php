<?php

declare(strict_types=1);

namespace App\Enum;

enum TransactionMeaning: string
{
    case Expense = 'expense';
    case Income = 'income';
    case Refund = 'refund';
    case OwnAccountTransfer = 'own_account_transfer';
    case CreditCardPayment = 'credit_card_payment';
    case Unknown = 'unknown';
}
