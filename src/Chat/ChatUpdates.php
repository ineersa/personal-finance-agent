<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\ChatConversation;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

/**
 * @phpstan-import-type Entry from ChatActivity
 * @phpstan-import-type Chart from NativeAssistantOutput
 */
final readonly class ChatUpdates
{
    public function __construct(private HubInterface $hub, private ChatTopic $topic, private Environment $twig, private LoggerInterface $logger)
    {
    }

    /** @return array{turns: list<array{question: string, reasoning: string, commentary: string, answer: string, activity?: list<Entry>, charts?: list<Chart>, execution?: string, error?: ?string}>, conversation_id: ?string, execution: ?string, error: ?string, saved: bool, active: bool, label: string, dom_id: string, composer_id: string, send_id: string} */
    public static function view(?ChatConversation $conversation, ?ExecutionInterface $execution = null, ?string $error = null): array
    {
        $id = $conversation->id ?? Workspace::EMPTY_ID;

        return ['turns' => $conversation->turns ?? [], 'conversation_id' => $conversation?->id, 'execution' => $execution?->getStatus()->value ?? $conversation?->generationStatus->value, 'error' => $error ?? $conversation?->error, 'saved' => $conversation->saved ?? false, 'active' => $conversation?->isActive() ?? false, 'label' => $conversation?->getLabel() ?? 'New chat', 'dom_id' => Workspace::DOM_ID.'-'.$id, 'composer_id' => Workspace::COMPOSER_DOM_ID.'-'.$id, 'send_id' => Workspace::SEND_DOM_ID.'-'.$id];
    }

    public function publish(ChatConversation $conversation, ?ExecutionInterface $execution = null, ?string $error = null): void
    {
        try {
            $this->hub->publish(new Update($this->topic->forUser($conversation->getUser()), $this->twig->render('chat/update.stream.html.twig', ['chat' => self::view($conversation, $execution, $error), 'chat_navigation' => null === $execution]), true));
        } catch (\Throwable) {
            // Delivery failures must not repeat inference.
            $this->logger->warning('Chat live update unavailable.', ['conversation_id' => $conversation->id]);
        }
    }
}
