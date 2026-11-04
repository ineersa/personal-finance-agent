<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\Migrator;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Query\Query;

final readonly class SqliteMigrator implements Migrator
{
    public function __construct(private Migrator $inner, private Connection $connection)
    {
    }

    /** @return array<string, Query[]> */
    public function migrate(MigrationPlanList $migrationsPlan, MigratorConfiguration $migratorConfiguration): array
    {
        if ($migratorConfiguration->isDryRun() || 0 === $migrationsPlan->count() || !$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return $this->inner->migrate($migrationsPlan, $migratorConfiguration);
        }
        // SQLite cannot change foreign_keys inside an existing transaction.
        if ($this->connection->isTransactionActive()) {
            throw new \LogicException('SQLite migrations require a connection without an active transaction.');
        }
        foreach ($migrationsPlan->getItems() as $plan) {
            if (!$plan->getMigration()->isTransactional()) {
                throw new \LogicException('The SQLite rebuild boundary requires transactional migrations.');
            }
        }
        $foreignKeys = (bool) $this->connection->fetchOne('PRAGMA foreign_keys');
        $this->connection->executeStatement('PRAGMA foreign_keys = OFF');
        try {
            $this->connection->beginTransaction();
            $queries = $this->inner->migrate($migrationsPlan, $migratorConfiguration);
            if (false !== $this->connection->fetchAssociative('PRAGMA foreign_key_check')) {
                throw new \RuntimeException('SQLite migration failed its foreign-key integrity check.');
            }
            $this->connection->commit();

            return $queries;
        } catch (\Throwable $error) {
            // Every nesting level here belongs to this boundary or the native migrator.
            while ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $error;
        } finally {
            $this->connection->executeStatement($foreignKeys ? 'PRAGMA foreign_keys = ON' : 'PRAGMA foreign_keys = OFF');
        }
    }
}
