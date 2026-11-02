<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Source;
use App\Entity\Statement;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\ProcessingStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class StatementRepository
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
            ->from(Statement::class, 'record')
            ->where('record.user = :user')
            ->innerJoin('record.source', 'source')
            ->andWhere('source.user = :user')
            ->setParameter('user', $user->getId());
    }

    public function findForUser(User $user, int $id): ?Statement
    {
        return $this->queryForUser($user)
            ->andWhere('record.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function transition(int $id, int $ownerId, string $filename, ProcessingStatus $from, ProcessingStatus $to, ?string $error = null, bool $withoutTransactions = false): bool
    {
        $ownedSources = $this->entityManager->createQueryBuilder()->select('source.id')->from(Source::class, 'source')->where('source.user = :owner');
        $query = $this->entityManager->createQueryBuilder()
            ->update(Statement::class, 'record')
            ->set('record.processingStatus', ':to')
            ->set('record.processingError', ':error')
            ->where('record.id = :id')
            ->andWhere('record.user = :owner')
            ->andWhere('record.storedFilename = :filename')
            ->andWhere('record.processingStatus = :from')
            ->andWhere('IDENTITY(record.source) IN ('.$ownedSources->getDQL().')')
            ->setParameter('to', $to->value)
            ->setParameter('error', $error)
            ->setParameter('id', $id)
            ->setParameter('owner', $ownerId)
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
