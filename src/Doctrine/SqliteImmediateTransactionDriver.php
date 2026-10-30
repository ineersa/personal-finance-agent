<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class SqliteImmediateTransactionDriver extends AbstractDriverMiddleware
{
    public function connect(
        #[\SensitiveParameter]
        array $params,
    ): DriverConnection {
        $connection = parent::connect($params);
        $connection->exec('PRAGMA busy_timeout = 5000');
        $connection->exec('PRAGMA foreign_keys = ON');
        $connection->exec('PRAGMA journal_mode = WAL');
        $connection->exec('PRAGMA synchronous = NORMAL');

        return new SqliteImmediateTransactionConnection($connection);
    }
}
