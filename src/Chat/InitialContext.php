<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\User;
use App\Finance\Sql\ReadOnlyQuery;
use Symfony\AI\Platform\Message\Content\File;
use Symfony\AI\Platform\Message\Template;
use Symfony\AI\Platform\Message\TemplateRenderer\StringTemplateRenderer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class InitialContext
{
    public function __construct(private ReadOnlyQuery $query, private StringTemplateRenderer $templates, #[Autowire('%kernel.project_dir%')] private string $root)
    {
    }

    public function forOwner(User $owner): string
    {
        $schema = [
            'schema' => $this->query->execute($owner, "SELECT name, sql FROM sqlite_schema WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"),
        ];

        return $this->templates->render(Template::string(File::fromFile($this->root.'/prompts/finance-context.txt')->asBinary()), [
            'data_schemas' => htmlspecialchars(json_encode($schema, \JSON_THROW_ON_ERROR), \ENT_XML1 | \ENT_QUOTES, 'UTF-8'),
            'reporting_currency' => $owner->getReportingCurrency()->value,
        ]);
    }
}
