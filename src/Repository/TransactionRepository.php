<?php

declare(strict_types=1);

namespace App\Repository;

use App\Finance\Entity\Statement;
use App\Finance\Entity\Transaction;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class TransactionRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function query(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()->select('record')->from(Transaction::class, 'record');
    }

    public function find(int $id): ?Transaction
    {
        return $this->entityManager->find(Transaction::class, $id);
    }

    /** @return list<Transaction> */
    public function draftsForStatement(Statement $statement): array
    {
        return $this->query()
            ->andWhere('record.statement = :statement')
            ->andWhere('record.isDraft = true')
            ->setParameter('statement', $statement)
            ->orderBy('record.id', 'ASC')
            ->getQuery()->getResult();
    }
}
