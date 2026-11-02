<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Statement;
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
        $sql = 'UPDATE statement SET processing_status = ?, processing_error = ? WHERE id = ? AND user_id = ? AND stored_filename = ? AND processing_status = ? AND source_id IN (SELECT id FROM source WHERE user_id = ?)';
        $parameters = [$to->value, $error, $id, $ownerId, $filename, $from->value, $ownerId];
        if ($withoutTransactions) {
            $sql .= ' AND NOT EXISTS (SELECT 1 FROM financial_transaction WHERE statement_id = ?)';
            $parameters[] = $id;
        }

        return 1 === $this->entityManager->getConnection()->executeStatement($sql, $parameters);
    }
}
