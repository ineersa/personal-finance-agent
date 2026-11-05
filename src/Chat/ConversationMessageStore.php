<?php

declare(strict_types=1);

namespace App\Chat;

use Doctrine\DBAL\Connection;
use Symfony\AI\Agent\Exception\ConflictException;
use Symfony\AI\Agent\Execution\ExecutionStatus;
use Symfony\AI\Agent\MessageStore\ConflictDetector;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Serializer\MessageBagNormalizer;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\TimeBasedUidInterface;

/** Native Agent storage over the existing owned JSON column; no history conversion or second serializer. */
#[Exclude]
final readonly class ConversationMessageStore implements MessageStoreInterface
{
    public function __construct(private Connection $connection, private MessageBagNormalizer $normalizer, private int $owner, private string $conversation, private string $turn, private string $attempt)
    {
    }

    public function load(AbstractUid&TimeBasedUidInterface $id): MessageBag
    {
        if ($id->toRfc4122() !== $this->conversation) {
            throw new \LogicException('Conversation does not belong to this generation.');
        }
        $history = $this->connection->fetchOne('SELECT history FROM chat_conversation WHERE id = ? AND user_id = ? AND generation_id = ? AND attempt_id = ? AND generation_status = ?', [$this->conversation, $this->owner, $this->turn, $this->attempt, ExecutionStatus::Running->value]);
        if (!\is_string($history)) {
            throw new ConflictException('Conversation attempt is no longer active.');
        }
        $data = json_decode($history, true, flags: \JSON_THROW_ON_ERROR);

        // Application instructions are refreshed each request, as in the original runtime.
        return [] === $data ? MessageBag::forId($id) : $this->normalizer->denormalize($data, MessageBag::class)->withoutSystemMessage();
    }

    public function save(MessageBag $messages): void
    {
        $this->connection->transactional(function () use ($messages): void {
            // BEGIN IMMEDIATE precedes this authoritative read; no ORM identity-map snapshot is used.
            ConflictDetector::assertContinues($this->load($messages->getId()), $messages);
            $this->connection->executeStatement('UPDATE chat_conversation SET history = ? WHERE id = ? AND user_id = ? AND generation_id = ? AND attempt_id = ?', [json_encode($this->normalizer->normalize($messages), \JSON_THROW_ON_ERROR), $this->conversation, $this->owner, $this->turn, $this->attempt]);
        });
    }

    public function delete(AbstractUid&TimeBasedUidInterface $id): void
    {
        // This generation-scoped adapter cannot delete an active conversation. Workspace owns retention.
        throw new \LogicException('Active conversation history cannot be deleted.');
    }
}
