<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\ChatConversation;
use App\Entity\User;
use App\Message\GenerateChat;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Exception\ConflictException;
use Symfony\AI\Agent\Execution\ExecutionStatus;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Serializer\MessageBagNormalizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final readonly class Workspace
{
    public const string CHAT_ROUTE = 'admin_chat';
    public const string SUBMIT_ROUTE = 'admin_chat_submit';
    public const string NEW_ROUTE = 'app_chat_new';
    public const string SAVE_ROUTE = 'app_chat_save';
    public const string SNAPSHOT_ROUTE = 'app_chat_snapshot';
    public const string SUBMIT_CSRF = 'chat-submit';
    public const string NEW_CSRF = 'chat-new';
    public const string SAVE_CSRF = 'chat-save';
    public const string DOM_ID = 'chat-workspace';
    public const string COMPOSER_DOM_ID = 'chat-composer-fields';
    public const string SEND_DOM_ID = 'chat-send';
    public const string EMPTY_ID = 'empty';
    public const string COMMENTARY_PHASE = 'commentary';
    public const int TEMPORARY_LIMIT = 5;

    public function __construct(private EntityManagerInterface $em, private MessageBagNormalizer $normalizer, private MessageBusInterface $bus, private InitialContext $context, private ChartFiles $files, private LoggerInterface $logger, #[Autowire('%env(CHAT_MODEL)%')] private string $model, #[Autowire('%env(CHAT_REASONING_EFFORT)%')] private string $effort)
    {
    }

    public function current(User $user, string $id = ''): ?ChatConversation
    {
        if ('' === $id) {
            return $this->em->getRepository(ChatConversation::class)->findOneBy(['user' => $user], ['id' => 'DESC']);
        }

        return $this->em->getRepository(ChatConversation::class)->findOneBy(['id' => $id, 'user' => $user]) ?? throw new NotFoundHttpException('Conversation unavailable.');
    }

    /** @return list<ChatConversation> */
    public function history(User $user): array
    {
        return $this->em->getRepository(ChatConversation::class)->findBy(['user' => $user], ['id' => 'DESC']);
    }

    public function startNew(User $user): ChatConversation
    {
        $removed = [];
        $conversation = $this->em->getConnection()->transactional(function () use ($user, &$removed): ChatConversation {
            $conversation = $this->create($user, $removed);
            $this->em->flush();

            return $conversation;
        });
        $this->removeCharts($removed);

        return $conversation;
    }

    public function save(User $user, string $id): ChatConversation
    {
        return $this->em->getConnection()->transactional(function () use ($user, $id): ChatConversation {
            $conversation = $this->current($user, $id) ?? throw new NotFoundHttpException();
            $this->em->refresh($conversation);
            $conversation->saved = true;
            $this->em->flush();

            return $conversation;
        });
    }

    public function submit(User $user, string $question, string $id = ''): ChatConversation
    {
        // Schema/file reads must not extend the central admission write transaction.
        $initial = '' === $id || [] === $this->current($user, $id)?->history ? $this->context->forOwner($user) : null;
        $removed = [];
        $conversation = $this->em->getConnection()->transactional(function () use ($user, $question, $id, $initial, &$removed): ChatConversation {
            $this->em->refresh($user);
            $identity = $user->getChatGptIdentity();
            if (null === $identity) {
                throw new \DomainException('Connect ChatGPT before sending a message.');
            }
            $conversation = '' === $id ? $this->create($user, $removed) : $this->current($user, $id);
            \assert($conversation instanceof ChatConversation);
            if ('' !== $id) {
                $this->em->refresh($conversation);
            }
            if ($conversation->isActive()) {
                throw new \DomainException('This chat is queued or responding. Wait for it to finish, or use another chat.');
            }
            $messages = [] === $conversation->history
                ? MessageBag::forId(UuidV7::fromString($conversation->id))->with(Message::ofUser($initial ?? throw new \LogicException('Initial context unavailable.')))
                : $this->normalizer->denormalize($conversation->history, MessageBag::class)->withoutSystemMessage();
            $conversation->history = $this->normalizer->normalize($messages->with(Message::ofUser($question)));
            $conversation->turns[] = ['question' => $question, 'reasoning' => '', 'commentary' => '', 'answer' => ''];
            $conversation->generationId = Uuid::v7()->toRfc4122();
            $conversation->attemptId = null;
            $conversation->markGeneration(ExecutionStatus::Pending);
            $conversation->accountIdentity = $identity;
            $this->em->flush();

            return $conversation;
        });
        $message = new GenerateChat($conversation->id, $conversation->generationId, $conversation->accountIdentity);
        try {
            // Admission is committed before broker I/O; no database transaction spans dispatch.
            $this->bus->dispatch($message);
        } catch (TransportException $error) {
            $this->finishDispatchFailure($message);
            throw $error;
        } finally {
            $this->removeCharts($removed);
        }

        return $conversation;
    }

    public function claim(GenerateChat $message): ?ChatConversation
    {
        return $this->em->getConnection()->transactional(function () use ($message): ?ChatConversation {
            $conversation = $this->em->find(ChatConversation::class, $message->conversationId);
            if (null === $conversation) {
                return null;
            }
            $this->em->refresh($conversation);
            if (!isset($message->turnId) || $message->turnId !== $conversation->generationId || !$conversation->isActive()) {
                return null;
            }
            // Each broker delivery owns a fresh attempt. Expiry can redeliver while an old worker lives.
            $conversation->attemptId = Uuid::v7()->toRfc4122();
            $owner = $conversation->getUser();
            $this->em->refresh($owner);
            if (!isset($message->accountIdentity) || $message->accountIdentity !== $owner->getChatGptIdentity() || null === $owner->getChatGptRegistrationId()) {
                $conversation->markGeneration(ExecutionStatus::Cancelled, 'ChatGPT was disconnected before this message started. Reconnect to continue this chat.');
                $this->em->flush();

                return $conversation;
            }
            $conversation->markGeneration(ExecutionStatus::Running);
            $this->em->flush();

            return $conversation;
        });
    }

    public function project(ChatConversation $conversation, string $turnId, string $attemptId, NativeAssistantOutput $output, ExecutionStatus $status = ExecutionStatus::Running, ?string $error = null): void
    {
        $this->em->getConnection()->transactional(function () use ($conversation, $turnId, $attemptId, $output, $status, $error): void {
            // Native saves and other tabs may have changed history/Save since the last callback.
            $this->em->refresh($conversation);
            if ($turnId !== $conversation->generationId || $attemptId !== $conversation->attemptId || ExecutionStatus::Running !== $conversation->generationStatus) {
                throw new ConflictException('This attempt is no longer active.');
            }
            $index = \count($conversation->turns) - 1;
            $conversation->turns[$index] = ['question' => $conversation->turns[$index]['question'], ...$output->transcript(), 'activity' => $output->activity->entries()];
            $conversation->markGeneration($status, $error);
            $this->em->flush();
        });
    }

    /** @param list<string> $removed */
    private function create(User $user, array &$removed): ChatConversation
    {
        $temporary = [];
        // UUIDv7 IDs encode creation time, so ascending IDs give oldest-created order (not last use).
        foreach (array_reverse($this->history($user)) as $chat) {
            $this->em->refresh($chat);
            if (!$chat->saved) {
                $temporary[] = $chat;
            }
        }
        $needed = \count($temporary) - self::TEMPORARY_LIMIT + 1;
        $victims = array_values(array_filter($temporary, static fn (ChatConversation $chat): bool => !$chat->isActive()));
        if ($needed > \count($victims)) {
            throw new \DomainException('All temporary chats are busy. Wait for a reply to finish or save a chat before starting another.');
        }
        foreach (\array_slice($victims, 0, max(0, $needed)) as $victim) {
            $removed[] = $victim->id;
            $this->em->remove($victim);
        }
        $conversation = new ChatConversation($user, $user->getChatGptIdentity(), $this->model, $this->effort);
        $this->em->persist($conversation);

        return $conversation;
    }

    private function finishDispatchFailure(GenerateChat $message): void
    {
        $this->em->getConnection()->transactional(function () use ($message): void {
            $conversation = $this->em->find(ChatConversation::class, $message->conversationId);
            if (null === $conversation) {
                return;
            }
            $this->em->refresh($conversation);
            if ($message->turnId === $conversation->generationId && ExecutionStatus::Pending === $conversation->generationStatus) {
                $conversation->markGeneration(ExecutionStatus::Failed, 'The message could not be sent to the worker. Please send it again.');
                $this->em->flush();
            }
        });
    }

    /** @param list<string> $removed */
    private function removeCharts(array $removed): void
    {
        foreach ($removed as $id) {
            try {
                $this->files->removeConversation($id);
            } catch (\Throwable) {
                $this->logger->warning('Pruned conversation chart cleanup unavailable.', ['conversation_id' => $id]);
            }
        }
    }
}
