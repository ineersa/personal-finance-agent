<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Message\Content\Commentary;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ToolCall;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Twig\TwigTest;

final class ChatExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('chat_exchanges', $this->exchanges(...))];
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
