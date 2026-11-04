<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Finance\TenantManagers;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;

// Owner-approved Alice lifecycle extension; never enabled outside APP_ENV=test.
final class TestTenantManagers extends TenantManagers
{
    private static bool $active = false;

    /** @var array<string, Connection> */
    private static array $connections = [];

    /** @var list<string> */
    private static array $created = [];

    public static function begin(): void
    {
        self::$active = true;
    }

    public function open(User $owner, bool $provision = false): EntityManagerInterface
    {
        $path = $this->path($owner);
        if (self::$active && isset(self::$connections[$path])) {
            $prototype = parent::open($owner, $provision);

            return new EntityManager(self::$connections[$path], $prototype->getConfiguration());
        }
        $existed = is_file($path);
        $em = parent::open($owner, $provision);
        if (self::$active) {
            self::$connections[$path] = $em->getConnection();
            $em->getConnection()->beginTransaction();
            if (!$existed) {
                self::$created[] = $path;
            }
        }

        return $em;
    }

    public function release(EntityManagerInterface $manager): void
    {
        if (self::$active) {
            $manager->clear();

            return;
        }
        parent::release($manager);
    }

    public static function rollback(): void
    {
        try {
            foreach (self::$connections as $connection) {
                while ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                $connection->close();
            }
            foreach (self::$created as $path) {
                foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
                    if (is_file($file) && !unlink($file)) {
                        throw new \RuntimeException('Cannot remove test-created tenant database.');
                    }
                }
            }
        } finally {
            self::$active = false;
            self::$connections = [];
            self::$created = [];
        }
    }
}
