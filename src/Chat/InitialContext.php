<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\User;
use App\Finance\Sql\ReadOnlyQuery;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class InitialContext
{
    public function __construct(private ReadOnlyQuery $query, #[Autowire('%kernel.project_dir%')] private string $root)
    {
    }

    public function forOwner(User $owner): string
    {
        $schema = [
            'reporting_currency' => $owner->getReportingCurrency()->value,
            'schema' => $this->query->execute($owner, "SELECT name, sql FROM sqlite_schema WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"),
        ];
        $context = file_get_contents($this->root.'/prompts/finance-context.txt');
        if (false === $context) {
            throw new \RuntimeException('Finance context is unavailable.');
        }

        return "[user-context]\n<data_schemas>\n".htmlspecialchars(json_encode($schema, \JSON_THROW_ON_ERROR), \ENT_XML1 | \ENT_QUOTES, 'UTF-8')."\n</data_schemas>\n<project_context>\n".$context."\n</project_context>";
    }
}
