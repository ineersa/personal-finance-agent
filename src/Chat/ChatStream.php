<?php

declare(strict_types=1);

namespace App\Chat;

use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Result\Stream\AssistantMessageStreamListener;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;

/** Transient native stream view; no persistence, replay, result rewriting or execution state. */
final class ChatStream
{
    private readonly AssistantMessageStreamListener $listener;
    /** @var list<MessageInterface> */
    private array $messages = [];

    public function __construct()
    {
        $this->listener = new AssistantMessageStreamListener();
    }

    public function observe(DeltaInterface $delta): void
    {
        $this->listener->accumulate($delta);
    }

    public function reset(): void
    {
        $this->messages = [];
        $this->listener->reset();
    }

    public function beginRound(): void
    {
        $message = $this->listener->getAssistantMessage();
        if ([] !== $message->getContent()) {
            $this->messages[] = $message;
        }
        $this->listener->reset();
    }

    public function completed(ToolResult $result): void
    {
        $this->beginRound();
        $this->messages[] = Message::ofToolCall($result->getToolCall(), json_encode($result->getResult(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    public function appendTo(MessageBag $input): MessageBag
    {
        return $input->merge(new MessageBag(...[...$this->messages, $this->listener->getAssistantMessage()]));
    }
}
