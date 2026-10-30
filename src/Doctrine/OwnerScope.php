<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final class OwnerScope
{
    private ?int $ownerId = null;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function getOwnerId(): ?int
    {
        return $this->ownerId;
    }

    /** @template T
     * @param callable(User): T $operation
     *
     * @return T
     */
    public function run(User $owner, callable $operation): mixed
    {
        if (null !== $this->ownerId || null === $owner->getId()) {
            throw new \LogicException('A persisted trusted owner and a non-nested scope are required.');
        }
        $this->entityManager->clear();
        $this->ownerId = $owner->getId();
        $filter = $this->entityManager->getFilters()->getFilter('owner');
        $filter->setParameter('owner_id', $this->ownerId, Types::INTEGER);
        try {
            return $operation($this->entityManager->getReference(User::class, $this->ownerId));
        } finally {
            $this->entityManager->clear();
            $this->ownerId = null;
            $filter->setParameter('owner_id', 0, Types::INTEGER);
        }
    }
}
