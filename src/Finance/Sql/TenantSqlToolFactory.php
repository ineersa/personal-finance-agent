<?php

declare(strict_types=1);

namespace App\Finance\Sql;

use App\Entity\User;
use App\Finance\TenantManagers;
use Symfony\AI\Agent\Toolbox\Toolbox;

final readonly class TenantSqlToolFactory
{
    public function __construct(private TenantManagers $tenants, private SqlExecutor $executor)
    {
    }

    // Call once per generation with the persisted owner loaded by the application, never model input.
    public function create(User $owner): Toolbox
    {
        if (\in_array('ROLE_ADMIN', $owner->getRoles(), true)) {
            throw new \LogicException('Administrators cannot query tenant finance storage.');
        }
        $path = $this->tenants->path($owner);
        if (is_link($path) || is_link(\dirname($path)) || !is_file($path)) {
            throw new \RuntimeException('Tenant database is unavailable.');
        }

        // Native Toolbox defaults to NullLogger: its debug logger would record SQL arguments.
        return new Toolbox([new TenantSqlTool($this->executor, $path, $owner->getReportingCurrency())]);
    }
}
