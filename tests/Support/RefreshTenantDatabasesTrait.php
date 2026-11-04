<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Finance\TenantManagers;
use Doctrine\Persistence\ManagerRegistry;
use Hautelook\AliceBundle\PhpUnit\RefreshDatabaseTrait;
use Symfony\Component\HttpKernel\KernelInterface;

trait RefreshTenantDatabasesTrait
{
    use RefreshDatabaseTrait {
        bootKernel as private bootCentralFixtures;
        ensureKernelShutdown as private shutdownCentralFixtures;
    }

    protected static function bootKernel(array $options = []): KernelInterface
    {
        $kernel = static::bootCentralFixtures($options);
        TestTenantManagers::begin();
        $container = $kernel->getContainer();
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        /** @var TenantManagers $tenants */
        $tenants = $container->get(TenantManagers::class);
        foreach ($doctrine->getManager()->getRepository(User::class)->findAll() as $owner) {
            if (!\in_array('ROLE_ADMIN', $owner->getRoles(), true)) {
                $tenants->open($owner);
            }
        }

        return $kernel;
    }

    protected static function ensureKernelShutdown(): void
    {
        try {
            TestTenantManagers::rollback();
        } finally {
            static::shutdownCentralFixtures();
        }
    }
}
