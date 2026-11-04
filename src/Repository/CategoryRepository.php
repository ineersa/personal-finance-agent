<?php

declare(strict_types=1);

namespace App\Repository;

use App\Finance\Entity\Category;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<Category> */
final class CategoryRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager, $entityManager->getClassMetadata(Category::class));
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
