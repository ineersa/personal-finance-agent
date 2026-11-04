<?php

declare(strict_types=1);

namespace App\Finance;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

// Registry/profiler metadata inspection must not require access to tenant data.
final class RejectTenantConnectionMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): Connection
            {
                throw new \LogicException('An authenticated ordinary tenant owner is required for finance data access.');
            }
        };
    }
}
