<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\ChatConversation;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Execution\ExecutionStatus;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\UuidV7;
use Twig\Environment;

/** @phpstan-import-type Turn from ChatView */
final readonly class ChatUpdates
{
    public function __construct(private MessageStoreInterface $store, private HubInterface $hub, private ChatTopic $topic, private Environment $twig, private LoggerInterface $logger)
    {
    }

    /** @return array{turns: list<Turn>, conversation_id: ?string, execution: ?string, error: ?string, label: string, dom_id: string, composer_id: string, send_id: string} */
    public function view(?ChatConversation $conversation, ?MessageBag $messages = null, ?ExecutionStatus $status = null, ?string $error = null): array
    {
        $id = $conversation->id ?? Workspace::EMPTY_ID;
        $turns = null === $conversation ? [] : ChatView::turns($messages ?? $this->store->load(UuidV7::fromString($conversation->id)), $conversation);
        $last = [] === $turns ? null : $turns[array_key_last($turns)]['execution'];

        return ['turns' => $turns, 'conversation_id' => $conversation?->id, 'execution' => $status->value ?? $last, 'error' => $error, 'label' => $conversation->label ?? 'New chat', 'dom_id' => Workspace::DOM_ID.'-'.$id, 'composer_id' => Workspace::COMPOSER_DOM_ID.'-'.$id, 'send_id' => Workspace::SEND_DOM_ID.'-'.$id];
    }

    public function publish(ChatConversation $conversation, MessageBag $messages, ?ExecutionStatus $status = null, ?string $error = null): void
    {
        try {
            $this->hub->publish(new Update($this->topic->forUser($conversation->getUser()), $this->twig->render('chat/update.stream.html.twig', ['chat' => $this->view($conversation, $messages, $status, $error)]), true));
        } catch (\Throwable) {
            // Delivery failure must not repeat inference; native persisted history remains authoritative.
            $this->logger->warning('Chat live update unavailable.', ['conversation_id' => $conversation->id]);
        }
    }
}
