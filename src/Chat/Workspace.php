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
    public const string FAVOURITE_ROUTE = 'app_chat_favourite';
    public const string DELETE_ROUTE = 'app_chat_delete';
    public const string SNAPSHOT_ROUTE = 'app_chat_snapshot';
    public const string SUBMIT_CSRF = 'chat-submit';
    public const string FAVOURITE_CSRF = 'chat-favourite';
    public const string DELETE_CSRF = 'chat-delete';
    public const string DOM_ID = 'chat-workspace';
    public const string COMPOSER_DOM_ID = 'chat-composer-fields';
    public const string SEND_DOM_ID = 'chat-send';
    public const string NAVIGATION_DOM_ID = 'chat-navigation-row';
    public const string DELETE_DOM_ID = 'chat-delete';
    public const string LINK_DOM_ID = 'chat-link';
    public const string EMPTY_ID = 'empty';
    public const string COMMENTARY_PHASE = 'commentary';

    public function __construct(private EntityManagerInterface $em, private MessageBagNormalizer $normalizer, private MessageBusInterface $bus, private InitialContext $context, private ChartFiles $files, private LoggerInterface $logger, #[Autowire('%env(CHAT_MODEL)%')] private string $model, #[Autowire('%env(CHAT_REASONING_EFFORT)%')] private string $effort)
    {
    }

    public function current(User $user, string $id = ''): ?ChatConversation
    {
        if ('' === $id) {
            return null;
        }

        return $this->em->getRepository(ChatConversation::class)->findOneBy(['id' => $id, 'user' => $user]) ?? throw new NotFoundHttpException('Conversation unavailable.');
    }

    /** @return list<array{id: string, label: string, favourite: bool, active: bool}> */
    public function navigation(User $user, bool $favourite, string $before, int $limit): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT id, label, saved AS favourite, generation_status FROM chat_conversation WHERE user_id = ? AND saved = ?'.('' === $before ? '' : ' AND id < ?').' ORDER BY id DESC LIMIT ?', '' === $before ? [$user->getId(), (int) $favourite, $limit] : [$user->getId(), (int) $favourite, $before, $limit], '' === $before ? [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER] : [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::INTEGER]);

        return array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'label' => (string) ($row['label'] ?? 'New chat'), 'favourite' => (bool) $row['favourite'], 'active' => !ExecutionStatus::from($row['generation_status'])->isFinished()], $rows);
    }

    /** @return array{id: string, label: string, favourite: bool, active: bool}|null */
    public function navigationSelection(User $user, string $id): ?array
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT id, label, saved, generation_status FROM chat_conversation WHERE id = ? AND user_id = ?', [$id, $user->getId()]);

        return false === $row ? null : ['id' => (string) $row['id'], 'label' => (string) $row['label'], 'favourite' => (bool) $row['saved'], 'active' => !ExecutionStatus::from($row['generation_status'])->isFinished()];
    }

    public function delete(User $user, string $id): void
    {
        $this->em->getConnection()->transactional(function () use ($user, $id): void {
            $conversation = $this->current($user, $id) ?? throw new NotFoundHttpException();
            $this->em->refresh($conversation);
            if ($conversation->isActive()) {
                throw new \DomainException('This chat is queued or responding. Wait for it to finish before deleting it.');
            }
            $this->em->remove($conversation);
            $this->em->flush();
        });
        $this->removeCharts([$id]);
    }

    public function favourite(User $user, string $id, bool $favourite): ChatConversation
    {
        return $this->em->getConnection()->transactional(function () use ($user, $id, $favourite): ChatConversation {
            $conversation = $this->current($user, $id) ?? throw new NotFoundHttpException();
            $this->em->refresh($conversation);
            $conversation->favourite = $favourite;
            $this->em->flush();

            return $conversation;
        });
    }

    public function submit(User $user, string $question, string $id = ''): ChatConversation
    {
        // Schema/file reads must not extend the central admission write transaction.
        $initial = '' === $id || [] === $this->current($user, $id)?->history ? $this->context->forOwner($user) : null;
        $conversation = $this->em->getConnection()->transactional(function () use ($user, $question, $id, $initial): ChatConversation {
            $this->em->refresh($user);
            $identity = $user->getChatGptIdentity();
            if (null === $identity) {
                throw new \DomainException('Connect ChatGPT before sending a message.');
            }
            $conversation = '' === $id ? $this->create($user) : $this->current($user, $id);
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
            if ([] === $conversation->turns) {
                $conversation->label = $question;
            }
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

    public function assertAttempt(ChatConversation $conversation, string $turnId, string $attemptId): void
    {
        // Ephemeral delivery needs fencing, not hydration or a write transaction.
        $current = $this->em->getConnection()->fetchOne('SELECT 1 FROM chat_conversation WHERE id = ? AND user_id = ? AND generation_id = ? AND attempt_id = ? AND generation_status = ?', [$conversation->id, $conversation->getUser()->getId(), $turnId, $attemptId, ExecutionStatus::Running->value]);
        if (false === $current) {
            throw new ConflictException('This attempt is no longer active.');
        }
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

    private function create(User $user): ChatConversation
    {
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
                $this->logger->warning('Conversation chart cleanup unavailable.', ['conversation_id' => $id]);
            }
        }
    }
}
