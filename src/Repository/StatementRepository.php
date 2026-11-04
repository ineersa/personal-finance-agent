<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\ProcessingStatus;
use App\Finance\Entity\Statement;
use App\Finance\Entity\Transaction;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class StatementRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function query(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()->select('record')->from(Statement::class, 'record');
    }

    public function find(int $id): ?Statement
    {
        return $this->query()
            ->andWhere('record.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function transition(int $id, string $filename, ProcessingStatus $from, ProcessingStatus $to, ?string $error = null, bool $withoutTransactions = false): bool
    {
        $query = $this->entityManager->createQueryBuilder()
            ->update(Statement::class, 'record')
            ->set('record.processingStatus', ':to')
            ->set('record.processingError', ':error')
            ->where('record.id = :id')
            ->andWhere('record.storedFilename = :filename')
            ->andWhere('record.processingStatus = :from')
            ->setParameter('to', $to->value)
            ->setParameter('error', $error)
            ->setParameter('id', $id)
            ->setParameter('filename', $filename)
            ->setParameter('from', $from->value);
        if ($withoutTransactions) {
            $transactions = $this->entityManager->createQueryBuilder()->select('transaction.id')->from(Transaction::class, 'transaction')->where('transaction.statement = :id');
            $query->andWhere($query->expr()->not($query->expr()->exists($transactions->getDQL())));
        }

        return 1 === $query->getQuery()->execute();
    }

    public function hasActiveImports(): bool
    {
        return 0 < (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(record.id)')->from(Statement::class, 'record')
            ->where('record.processingStatus IN (:statuses)')
            ->setParameter('statuses', [ProcessingStatus::Queued->value, ProcessingStatus::Running->value])
            ->getQuery()->getSingleScalarResult();
    }
}
