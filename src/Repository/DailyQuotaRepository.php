<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DailyQuota;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DailyQuota> */
final class DailyQuotaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyQuota::class);
    }
}
