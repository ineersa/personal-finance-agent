<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\ChatConversation;
use App\Entity\User;
use App\Message\GenerateChat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Serializer\MessageBagNormalizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\UuidV7;

final readonly class Workspace
{
    public const string CHAT_ROUTE = 'admin_chat';
    public const string SUBMIT_ROUTE = 'admin_chat_submit';
    public const string NEW_ROUTE = 'app_chat_new';
    public const string SNAPSHOT_ROUTE = 'app_chat_snapshot';
    public const string SUBMIT_CSRF = 'chat-submit';
    public const string NEW_CSRF = 'chat-new';
    public const string DOM_ID = 'chat-workspace';
    public const string COMPOSER_DOM_ID = 'chat-composer-fields';
    public const string COMMENTARY_PHASE = 'commentary';

    public function __construct(private EntityManagerInterface $em, private MessageBagNormalizer $normalizer, private MessageBusInterface $bus, private InitialContext $context, private ChartImages $images, #[Autowire('%env(CHAT_MODEL)%')] private string $model, #[Autowire('%env(CHAT_REASONING_EFFORT)%')] private string $effort)
    {
    }

    public function current(User $user): ?ChatConversation
    {
        $identity = $user->getChatGptIdentity();
        if (null === $identity) {
            return null;
        }

        return $this->em->getRepository(ChatConversation::class)->findOneBy(['user' => $user, 'accountIdentity' => $identity]);
    }

    public function startNew(User $user): void
    {
        $conversation = $this->current($user);
        if (null !== $conversation) {
            $this->em->remove($conversation);
            $this->em->flush();
            $this->images->removeConversation($conversation->id);
        }
    }

    public function submit(User $user, string $question): void
    {
        $identity = $user->getChatGptIdentity();
        if (null === $identity) {
            throw new \DomainException('Connect ChatGPT before sending a message.');
        }
        $conversation = $this->current($user) ?? new ChatConversation($user, $identity, $this->model, $this->effort);
        $messages = [] === $conversation->history
            ? MessageBag::forId(UuidV7::fromString($conversation->id))->with(Message::ofUser($this->context->forOwner($user)))
            : $this->normalizer->denormalize($conversation->history, MessageBag::class);
        $conversation->history = $this->normalizer->normalize($messages->with(Message::ofUser($question)));
        $conversation->turns[] = ['question' => $question, 'reasoning' => '', 'commentary' => '', 'answer' => ''];
        $this->em->persist($conversation);
        $this->em->flush();
        $this->bus->dispatch(new GenerateChat($conversation->id));
    }
}
