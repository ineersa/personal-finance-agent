<?php

declare(strict_types=1);

namespace App\Chat;

use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageItem;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/** Request-only subset of Hatfield's exact-model history policy; Agent keeps the originals. */
final class ModelHistoryInputProcessor implements InputProcessorInterface
{
    private const string TOOL_ID_DELIMITER = '|';

    public function processInput(Input $input): void
    {
        $bag = $input->getMessageBag();
        $messages = [];
        $toolCallIds = [];
        foreach ($bag as $message) {
            if (!$message instanceof AssistantMessage) {
                if ($message instanceof ToolCallMessage && isset($toolCallIds[$message->getToolCall()->getId()])) {
                    $call = $message->getToolCall();
                    $copy = (new ToolCallMessage($this->copyToolCall($call, $toolCallIds[$call->getId()]), ...$message->getContent()))->withId($message->getId());
                    $copy->getMetadata()->set($message->getMetadata()->all());
                    $messages[] = $copy;
                    continue;
                }
                $messages[] = $message;
                continue;
            }
            // Results belong to the preceding assistant round, not every historical call ID.
            $toolCallIds = [];
            $usage = $message->getMetadata()->get('token_usage');
            // A missing identity or a provider snapshot unequal to the requested alias is unknown,
            // not proof that opaque signatures are portable. Never guess alias equivalence.
            if ($usage instanceof TokenUsageInterface && $usage->getModel() === $input->getModel()) {
                $messages[] = $message;
                continue;
            }
            $parts = [];
            foreach ($message->getContent() as $part) {
                if ($part instanceof ToolCall && str_contains($part->getId(), self::TOOL_ID_DELIMITER)) {
                    // The installed ChatGPT bridge encodes call_id|native_item_id in one ID.
                    $toolCallIds[$part->getId()] = explode(self::TOOL_ID_DELIMITER, $part->getId(), 2)[0];
                    $parts[] = $this->copyToolCall($part, $toolCallIds[$part->getId()]);
                    continue;
                }
                $parts[] = match (true) {
                    $part instanceof Text => $this->unsignedText($part),
                    $part instanceof Thinking => new Text($part->getContent()),
                    default => $part,
                };
            }
            $copy = (new AssistantMessage(...$parts))->withId($message->getId());
            $copy->getMetadata()->set($message->getMetadata()->all());
            $messages[] = $copy;
        }
        $request = MessageBag::forId($bag->getId(), ...$messages);
        $request->getMetadata()->set($bag->getMetadata()->all());
        $input->setMessageBag($request);
    }

    private function copyToolCall(ToolCall $call, string $id): ToolCall
    {
        return new ToolCall($id, $call->getName(), $call->getArguments(), $call->getSignature());
    }

    private function unsignedText(Text $text): Text
    {
        // The ChatGPT bridge stores phase inside its signed item, not on Text itself.
        $phase = $text->getPhase() ?? (MessageItem::fromText($text)['phase'] ?? null);

        return new Text($text->getText(), phase: \is_string($phase) ? $phase : null);
    }
}
