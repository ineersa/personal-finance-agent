<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DraftTransaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DraftTransaction> */
final class DraftTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DraftTransaction::class);
    }
}
