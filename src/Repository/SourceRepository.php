<?php

declare(strict_types=1);

namespace App\Repository;

use App\Finance\Entity\Source;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class SourceRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function query(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()->select('record')->from(Source::class, 'record');
    }

    public function find(int $id): ?Source
    {
        return $this->query()
            ->andWhere('record.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
