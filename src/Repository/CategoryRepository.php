<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Category> */
final class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /** @return array<string, Category> */
    public function findAllIndexedByName(): array
    {
        return $this->createQueryBuilder('category')
            ->indexBy('category', 'category.name')
            ->getQuery()
            ->getResult();
    }
}
