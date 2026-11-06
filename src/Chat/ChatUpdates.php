<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\ChatConversation;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\UuidV7;
use Twig\Environment;

/** Native history for pages; targeted HTML-over-the-wire for live presentation. */
final readonly class ChatUpdates
{
    public function __construct(private MessageStoreInterface $store, private HubInterface $hub, private ChatTopic $topic, private Environment $twig, private LoggerInterface $logger)
    {
    }

    /** @return array{messages: MessageBag, conversation: ?ChatConversation, conversation_id: ?string, label: string, dom_id: string, composer_id: string, send_id: string} */
    public function view(?ChatConversation $conversation): array
    {
        $id = $conversation->id ?? Workspace::EMPTY_ID;

        return ['messages' => null === $conversation ? new MessageBag() : $this->store->load(UuidV7::fromString($conversation->id)), 'conversation' => $conversation, 'conversation_id' => $conversation?->id, 'label' => $conversation->label ?? 'New chat', 'dom_id' => Workspace::DOM_ID.'-'.$id, 'composer_id' => Workspace::COMPOSER_DOM_ID.'-'.$id, 'send_id' => Workspace::SEND_DOM_ID.'-'.$id];
    }

    /** @param array<string, mixed> $variables Native objects and template parameters, never a parallel transcript. */
    public function publish(ChatConversation $conversation, string $template, array $variables): void
    {
        try {
            $this->hub->publish(new Update($this->topic->forUser($conversation->getUser()), $this->twig->render($template, ['conversation' => $conversation, ...$variables]), true));
        } catch (\Throwable) {
            // Delivery failure must not repeat inference; native persisted history remains authoritative.
            $this->logger->warning('Chat live update unavailable.', ['conversation_id' => $conversation->id]);
        }
    }
}
