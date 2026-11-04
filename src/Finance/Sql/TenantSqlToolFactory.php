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
        // Native Toolbox defaults to NullLogger: debug logging would record SQL arguments.
        return new Toolbox([$this->bind($owner)]);
    }

    /** @return array<string, mixed> */
    public function schema(User $owner): array
    {
        return $this->bind($owner)->schema();
    }

    private function bind(User $owner): TenantSqlTool
    {
        if (\in_array('ROLE_ADMIN', $owner->getRoles(), true)) {
            throw new \LogicException('Administrators cannot query tenant finance storage.');
        }
        $path = $this->tenants->path($owner);
        if (is_link($path) || is_link(\dirname($path)) || !is_file($path)) {
            throw new \RuntimeException('Tenant database is unavailable.');
        }

        return new TenantSqlTool($this->executor, $path, $owner->getReportingCurrency());
    }
}
