<?php

declare(strict_types=1);

namespace App\Twig;

use App\Chat\ChartToolset;
use App\Finance\Sql\TenantSqlTool;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Message\Content\Commentary;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ToolCall;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Twig\TwigTest;

final class ChatExtension extends AbstractExtension
{
    private const string CHART_PREFIX = ChartToolset::NAME.'_';

    public function getFunctions(): array
    {
        return [
            new TwigFunction('chat_exchanges', $this->exchanges(...)),
            new TwigFunction('chat_tool_label', $this->toolLabel(...)),
            new TwigFunction('chat_tool_error', $this->toolError(...)),
        ];
    }

    public function toolLabel(ToolCall $tool, bool $finished): string
    {
        if (TenantSqlTool::QUERY_NAME === $tool->getName()) {
            return $finished ? 'Finance query finished' : 'Getting finance data';
        }
        if (self::CHART_PREFIX.ChartToolset::INFO_TOOL === $tool->getName()) {
            return $finished ? 'Chart discovery finished' : 'Discovering chart tools';
        }
        if (str_starts_with($tool->getName(), self::CHART_PREFIX)) {
            return $finished ? 'Chart request finished' : 'Building chart';
        }

        return $finished ? 'Tool request finished' : 'Running tool';
    }

    public function toolError(ToolCall $tool, ToolResult|ToolCallMessage|null $result): bool
    {
        if (null === $result) {
            return false;
        }
        if ($result->isError()) {
            return true;
        }
        if (TenantSqlTool::QUERY_NAME !== $tool->getName()) {
            return false;
        }
        $payload = $result instanceof ToolResult ? $result->getResult() : $result->asText();
        if (\is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        return \is_array($payload) && \array_key_exists(TenantSqlTool::ERROR_FIELD, $payload);
    }

    public function getTests(): array
    {
        return [
            new TwigTest('chat_text', static fn (mixed $part): bool => $part instanceof Text),
            new TwigTest('chat_thinking', static fn (mixed $part): bool => $part instanceof Thinking),
            new TwigTest('chat_commentary', static fn (mixed $part): bool => $part instanceof Commentary),
            new TwigTest('chat_tool_call', static fn (mixed $part): bool => $part instanceof ToolCall),
            new TwigTest('chat_tool_result', static fn (mixed $result): bool => $result instanceof ToolResult),
        ];
    }

    /** Groups native objects only; the first user message is the private finance context.
     * @return array<string, list<MessageInterface>>
     */
    public function exchanges(MessageBag $messages): array
    {
        $exchanges = [];
        $context = true;
        $user = null;
        foreach ($messages as $message) {
            if ($message instanceof UserMessage) {
                if ($context) {
                    $context = false;
                    continue;
                }
                $user = $message->getId()->toRfc4122();
            }
            if (null !== $user) {
                $exchanges[$user][] = $message;
            }
        }

        return $exchanges;
    }
}
