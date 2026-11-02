<?php

declare(strict_types=1);

namespace App\Demo;

use App\Entity\PendingStatementFileCleanup;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Fidry\AliceDataFixtures\Persistence\PurgeMode;
use Fidry\AliceDataFixtures\Persistence\PurgerFactoryInterface;
use Fidry\AliceDataFixtures\Persistence\PurgerInterface;

// Alice's native factory has no exclusion option; adapt only its purger interface.
final readonly class StatementCleanupPurgerFactory implements PurgerFactoryInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function create(PurgeMode $mode, ?PurgerInterface $purger = null): PurgerInterface
    {
        $native = new ORMPurger($this->em, [$this->em->getClassMetadata(PendingStatementFileCleanup::class)->getTableName()]);
        $native->setPurgeMode($mode->getValue());

        return new readonly class($native) implements PurgerInterface {
            public function __construct(private ORMPurger $purger)
            {
            }

            public function purge(): void
            {
                $this->purger->purge();
            }
        };
    }
}
