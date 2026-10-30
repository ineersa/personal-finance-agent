<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Transaction;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class TransactionRepository
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
            ->from(Transaction::class, 'record')
            ->where('record.user = :user')
            ->innerJoin('record.source', 'source')
            ->innerJoin('record.statement', 'statement')
            ->innerJoin('statement.source', 'statementSource')
            ->andWhere('source.user = :user')
            ->andWhere('statement.user = :user')
            ->andWhere('statementSource.user = :user')
            ->setParameter('user', $user->getId());
    }

    public function findForUser(User $user, int $id): ?Transaction
    {
        return $this->queryForUser($user)
            ->andWhere('record.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
