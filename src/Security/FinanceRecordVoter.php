<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Source;
use App\Entity\Statement;
use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\UserRecord;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, UserRecord|null> */
final class FinanceRecordVoter extends Voter
{
    public const string ACCESS = 'FINANCE_RECORD';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ACCESS === $attribute && (null === $subject || $subject instanceof UserRecord);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || null === $user->getId() || \in_array('ROLE_ADMIN', $token->getRoleNames(), true)) {
            return false;
        }

        // A null subject authorizes entry to the ordinary-user finance workspace,
        // including settings, empty indexes and creation forms. EasyAdmin also
        // checks this before createEntity(). Bound records still require ownership.
        return null === $subject || $this->owns($user, $subject);
    }

    private function owns(User $user, UserRecord $record): bool
    {
        if ($user->getId() !== $record->getUser()->getId()) {
            return false;
        }
        if ($record instanceof Transaction) {
            return !$record->isDraft && $this->owns($user, $record->source) && $this->owns($user, $record->statement);
        }
        if ($record instanceof Statement) {
            return $this->owns($user, $record->source);
        }

        return $record instanceof Source;
    }
}
