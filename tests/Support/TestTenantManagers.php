<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Finance\TenantManagers;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;

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
            $events = $prototype->getEventManager();
            \assert($events instanceof EventManager);

            return new EntityManager(self::$connections[$path], $prototype->getConfiguration(), $events);
        }
        $existed = is_file($path);
        $em = parent::open($owner, $provision);
        if (self::$active) {
            if (!$existed) {
                self::$created[] = $path;
            }
            // Schema provisioning owns its transaction; enlist only subsequent data access.
            // Newly provisioned files are still removed by the existing teardown.
            if (!$provision) {
                self::$connections[$path] = $em->getConnection();
                $em->getConnection()->beginTransaction();
            }
        }

        return $em;
    }

    public function release(EntityManagerInterface $manager): void
    {
        if (self::$active && \in_array($manager->getConnection(), self::$connections, true)) {
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
                    if (is_link(\dirname($file)) || is_link($file) || is_dir($file)) {
                        throw new \RuntimeException('Refusing unsafe test-created tenant storage.');
                    }
                    (new Filesystem())->remove($file);
                }
            }
        } finally {
            self::$active = false;
            self::$connections = [];
            self::$created = [];
        }
    }
}
