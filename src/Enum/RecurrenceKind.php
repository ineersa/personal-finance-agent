<?php

declare(strict_types=1);

namespace App\Enum;

enum RecurrenceKind: string
{
    case RecurringBill = 'recurring_bill';
    case Subscription = 'subscription';
    case OtherRecurring = 'other_recurring';
    case NonRecurring = 'non_recurring';
    case Unknown = 'unknown';
}
