<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\ChatConversation;
use Symfony\AI\Agent\Execution\ExecutionStatus;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Commentary;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Read-only presentation of native content. Never serializes, saves or restores execution.
 *
 * @phpstan-type Entry array{kind: string, text: string, call_id: ?string, name: ?string, arguments: ?string, result: ?string, status: ?string}
 * @phpstan-type Turn array{question: string, message: string, answer: string, reasoning: string, commentary: string, activity: list<Entry>, charts: list<array{file: string}>, execution: ?string}
 */
final class ChatView
{
    public const string TOOL = 'tool';
    public const string REASONING = 'reasoning';
    public const string COMMENTARY = 'commentary';

    /** @return list<Turn> */
    public static function turns(MessageBag $messages, ChatConversation $conversation): array
    {
        $turns = [];
        $context = true;
        foreach ($messages as $message) {
            if ($message instanceof UserMessage) {
                // The first native user message is the trusted initial finance reference context.
                if ($context) {
                    $context = false;
                    continue;
                }
                $turns[] = ['question' => $message->asText() ?? '', 'message' => $message->getId()->toRfc4122(), 'answer' => '', 'reasoning' => '', 'commentary' => '', 'activity' => [], 'charts' => [], 'execution' => null];
            } elseif ([] !== $turns) {
                $index = \count($turns) - 1;
                if ($message instanceof AssistantMessage) {
                    foreach ($message->getContent() as $part) {
                        if ($part instanceof Text && Workspace::COMMENTARY_PHASE !== $part->getPhase()) {
                            $turns[$index]['answer'] .= $part->getText();
                            $turns[$index]['execution'] = ExecutionStatus::Completed->value;
                        } elseif ($part instanceof Thinking || $part instanceof Commentary || $part instanceof Text) {
                            $kind = $part instanceof Thinking ? self::REASONING : self::COMMENTARY;
                            $text = $part instanceof Text ? $part->getText() : $part->getContent();
                            $turns[$index][$kind] .= $text;
                            $turns[$index]['activity'][] = self::entry($kind, $text);
                        } elseif ($part instanceof ToolCall) {
                            $turns[$index]['activity'][] = [...self::entry(self::TOOL), 'call_id' => $part->getId(), 'name' => $part->getName(), 'arguments' => json_encode($part->getArguments(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), 'status' => ExecutionStatus::Running->value];
                        }
                    }
                } elseif ($message instanceof ToolCallMessage) {
                    foreach ($turns[$index]['activity'] as &$entry) {
                        if ($entry['call_id'] === $message->getToolCall()->getId()) {
                            $entry['result'] = $message->asText();
                            $entry['status'] = ExecutionStatus::Completed->value;
                        }
                    }
                    unset($entry);
                }
            }
        }
        foreach ($turns as &$turn) {
            foreach ($conversation->charts as $chart) {
                if ($chart['message'] === $turn['message']) {
                    $turn['charts'][] = ['file' => $chart['file']];
                }
            }
        }

        return $turns;
    }

    /** @return Entry */
    private static function entry(string $kind, string $text = ''): array
    {
        return ['kind' => $kind, 'text' => $text, 'call_id' => null, 'name' => null, 'arguments' => null, 'result' => null, 'status' => null];
    }
}
