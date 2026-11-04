<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Migrator;

final class SqliteMigratorFactory
{
    public function __invoke(DependencyFactory $factory): Migrator
    {
        // DependencyFactory resolves the native implementation during factory decoration.
        return new SqliteMigrator($factory->getMigrator(), $factory->getConnection());
    }
}
