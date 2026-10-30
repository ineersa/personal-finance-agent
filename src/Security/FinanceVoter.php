<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Source;
use App\Entity\Statement;
use App\Entity\Transaction;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, Source|Statement|Transaction> */
final class FinanceVoter extends Voter
{
    public const string VIEW = 'FINANCE_VIEW';
    public const string EDIT = 'FINANCE_EDIT';
    public const string DELETE = 'FINANCE_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && ($subject instanceof Source || $subject instanceof Statement || $subject instanceof Transaction);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || null === $user->getId()) {
            return false;
        }

        return $this->belongsTo($subject, $user);
    }

    private function belongsTo(Source|Statement|Transaction $record, User $user): bool
    {
        if ($record->getUser()->getId() !== $user->getId()) {
            return false;
        }
        if ($record instanceof Statement) {
            return $this->belongsTo($record->source, $user);
        }
        if ($record instanceof Transaction) {
            return $this->belongsTo($record->statement, $user) && $this->belongsTo($record->source, $user);
        }

        return true;
    }
}
