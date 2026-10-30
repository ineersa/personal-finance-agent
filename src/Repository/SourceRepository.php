<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Source;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class SourceRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function queryForUser(User $user): QueryBuilder
    {
        if (null === $user->getId()) {
            throw new \InvalidArgumentException('A persisted trusted user is required.');
        }

        return $this->entityManager->createQueryBuilder()
            ->select('record')
            ->from(Source::class, 'record')
            ->where('record.user = :user')
            ->setParameter('user', $user->getId());
    }

    public function findForUser(User $user, int $id): ?Source
    {
        return $this->queryForUser($user)
            ->andWhere('record.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
