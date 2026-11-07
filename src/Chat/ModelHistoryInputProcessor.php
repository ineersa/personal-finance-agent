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
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/** Request-only subset of Hatfield's exact-model history policy; Agent keeps the originals. */
final class ModelHistoryInputProcessor implements InputProcessorInterface
{
    public function processInput(Input $input): void
    {
        $bag = $input->getMessageBag();
        $messages = [];
        foreach ($bag as $message) {
            if (!$message instanceof AssistantMessage) {
                $messages[] = $message;
                continue;
            }
            $usage = $message->getMetadata()->get('token_usage');
            // A missing identity or a provider snapshot unequal to the requested alias is unknown,
            // not proof that opaque signatures are portable. Never guess alias equivalence.
            if ($usage instanceof TokenUsageInterface && $usage->getModel() === $input->getModel()) {
                $messages[] = $message;
                continue;
            }
            $parts = [];
            foreach ($message->getContent() as $part) {
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

    private function unsignedText(Text $text): Text
    {
        // The ChatGPT bridge stores phase inside its signed item, not on Text itself.
        $phase = $text->getPhase() ?? (MessageItem::fromText($text)['phase'] ?? null);

        return new Text($text->getText(), phase: \is_string($phase) ? $phase : null);
    }
}
