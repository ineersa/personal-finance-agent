<?php

declare(strict_types=1);

namespace App\Chat;

use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionExceptionInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Preserve native execution feedback as structured content before its error signal is lost. */
#[Exclude]
final readonly class ExplicitToolErrors implements ToolboxInterface
{
    public const string ERROR_FIELD = 'error';

    public function __construct(private ToolboxInterface $inner)
    {
    }

    public function getTools(): array
    {
        return $this->inner->getTools();
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        try {
            return $this->inner->execute($toolCall);
        } catch (ToolExecutionExceptionInterface $error) {
            // Use exactly the native model-facing feedback, not exception messages or traces.
            return new ToolResult($toolCall, [self::ERROR_FIELD => $error->getToolCallResult()]);
        }
    }
}
