<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\ChatConversation;
use App\Entity\User;
use App\Enum\ProcessingStatus;
use App\Message\GenerateChat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Serializer\MessageBagNormalizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final readonly class Workspace
{
    public const string CHAT_ROUTE = 'admin_chat';
    public const string SUBMIT_ROUTE = 'app_chat_submit';
    public const string NEW_ROUTE = 'app_chat_new';
    public const string SNAPSHOT_ROUTE = 'app_chat_snapshot';
    public const string SUBMIT_CSRF = 'chat-submit';
    public const string NEW_CSRF = 'chat-new';
    public const string DOM_ID = 'chat-workspace';
    public const string ADMIN_ROLE = 'ROLE_ADMIN';
    public const string COMMENTARY_PHASE = 'commentary';

    public function __construct(private EntityManagerInterface $em, private MessageBagNormalizer $normalizer, private LockFactory $locks, private MessageBusInterface $bus, #[Autowire('%env(CHAT_MODEL)%')] private string $model, #[Autowire('%env(CHAT_REASONING_EFFORT)%')] private string $effort)
    {
    }

    public function current(User $user): ?ChatConversation
    {
        $identity = $user->getChatGptIdentity();
        if (null === $identity || \in_array(self::ADMIN_ROLE, $user->getRoles(), true)) {
            return null;
        }

        return $this->em->getRepository(ChatConversation::class)->findOneBy(['user' => $user, 'accountIdentity' => $identity]);
    }

    public function startNew(User $user): void
    {
        $identity = $user->getChatGptIdentity();
        if (null === $identity || \in_array(self::ADMIN_ROLE, $user->getRoles(), true)) {
            return;
        }
        $lock = $this->locks->createLock(self::SUBMIT_CSRF.$identity);
        if (!$lock->acquire()) {
            throw new \DomainException('Wait for your message to finish submitting before starting a new chat.');
        }
        try {
            $conversation = $this->current($user);
            if (null === $conversation) {
                return;
            }
            if ($conversation->busy()) {
                throw new \DomainException('Wait for the current response before starting a new chat.');
            }
            $this->em->remove($conversation);
            $this->em->flush();
        } finally {
            $lock->release();
        }
    }

    public function submit(User $user, string $question): void
    {
        $identity = $user->getChatGptIdentity();
        $ownerId = $user->getId();
        if (null === $identity || null === $ownerId || \in_array(self::ADMIN_ROLE, $user->getRoles(), true)) {
            throw new \DomainException('Connect ChatGPT before sending a message.');
        }
        $lock = $this->locks->createLock(self::SUBMIT_CSRF.$identity);
        if (!$lock->acquire()) {
            throw new \DomainException('Your previous message is still being submitted.');
        }
        try {
            $conversation = $this->current($user) ?? new ChatConversation($user, $identity, $this->model, $this->effort);
            if ($conversation->busy()) {
                throw new \DomainException('Wait for the current response before sending another message.');
            }
            $messages = [] === $conversation->history
                ? MessageBag::forId(UuidV7::fromString($conversation->id))
                : $this->normalizer->denormalize($conversation->history, MessageBag::class);
            $conversation->history = $this->normalizer->normalize($messages->with(Message::ofUser($question)));
            $conversation->turns[] = ['question' => $question, 'reasoning' => '', 'commentary' => '', 'answer' => ''];
            $conversation->status = ProcessingStatus::Queued;
            $conversation->generationId = Uuid::v7()->toRfc4122();
            $conversation->error = null;
            $this->em->persist($conversation);
            $this->em->flush();
            try {
                $this->bus->dispatch(new GenerateChat($conversation->id, $ownerId, $identity, $conversation->generationId));
            } catch (\Throwable) {
                $conversation->status = ProcessingStatus::Failed;
                $conversation->error = 'The message could not be queued. Please try again.';
                $this->em->flush();
            }
        } finally {
            $lock->release();
        }
    }
}
