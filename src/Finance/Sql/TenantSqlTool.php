<?php

declare(strict_types=1);

namespace App\Finance\Sql;

use App\Enum\ReportingCurrency;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Tool\ToolEffectHints;

#[AsTool(name: 'finance_schema', description: 'Read current tenant finance table definitions and reporting currency.', method: 'schema', effectHints: new ToolEffectHints(readOnlyHint: true, openWorldHint: false))]
#[AsTool(name: 'finance_query', description: 'Execute one bounded read-only SQLite query against the current owner finance database.', effectHints: new ToolEffectHints(readOnlyHint: true, openWorldHint: false))]
final readonly class TenantSqlTool
{
    public function __construct(private SqlExecutor $executor, private string $path, private ReportingCurrency $currency)
    {
    }

    /** @return array<string, mixed> */
    public function __invoke(string $sql): array
    {
        return $this->executor->execute($this->path, $sql);
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return [
            'reporting_currency' => $this->currency->value,
            'schema' => $this->executor->execute($this->path, "SELECT name, sql FROM sqlite_schema WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"),
        ];
    }
}
