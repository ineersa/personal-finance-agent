<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\ProcessingStatus;
use App\Finance\Entity\Category;
use App\Finance\Entity\FinanceRecord;
use App\Finance\Entity\Transaction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, FinanceRecord|Category|null> */
final class FinanceRecordVoter extends Voter
{
    public const string ACCESS = 'FINANCE_RECORD';

    public function __construct(#[Autowire(service: 'doctrine.orm.finance_entity_manager')] private readonly EntityManagerInterface $finance)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ACCESS === $attribute && (null === $subject || $subject instanceof FinanceRecord || $subject instanceof Category);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || null === $user->getId() || \in_array('ROLE_ADMIN', $token->getRoleNames(), true)) {
            return false;
        }
        if (null === $subject) {
            return true;
        }
        if (null !== $subject->getId() && !$this->finance->contains($subject)) {
            return false;
        }

        return !$subject instanceof Transaction || !$subject->isDraft || ProcessingStatus::Completed === $subject->statement->processingStatus;
    }
}
