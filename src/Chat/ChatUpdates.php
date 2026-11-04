<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\ChatConversation;
use App\Enum\ProcessingStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

final readonly class ChatUpdates
{
    public function __construct(private HubInterface $hub, private ChatTopic $topic, private Environment $twig, private LoggerInterface $logger)
    {
    }

    /** @return array{turns: list<array{question: string, reasoning: string, commentary: string, answer: string}>, status: string, busy: bool, error: ?string} */
    public static function view(?ChatConversation $conversation): array
    {
        return ['turns' => $conversation->turns ?? [], 'status' => $conversation?->status->value ?? ProcessingStatus::Completed->value, 'busy' => $conversation?->busy() ?? false, 'error' => $conversation?->error];
    }

    public function publish(ChatConversation $conversation): void
    {
        try {
            $this->hub->publish(new Update($this->topic->forUser($conversation->getUser()), $this->twig->render('chat/update.stream.html.twig', ['chat' => self::view($conversation)]), true));
        } catch (\Throwable) {
            // Persisted status remains authoritative; do not retry inference on delivery failure.
            $this->logger->warning('Chat live update unavailable.', ['conversation_id' => $conversation->id]);
        }
    }
}
