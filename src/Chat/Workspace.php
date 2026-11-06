<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\ChatConversation;
use App\Entity\User;
use App\Message\GenerateChat;
use App\Repository\ChatConversationRepository;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
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

    public function __construct(private ChatConversationRepository $conversations, private MessageStoreInterface $store, private MessageBusInterface $bus, private InitialContext $context, private ChartFiles $files, private LoggerInterface $logger)
    {
    }

    public function current(User $user, string $id = ''): ?ChatConversation
    {
        return '' === $id ? null : ($this->conversations->owned($user, $id) ?? throw new NotFoundHttpException('Conversation unavailable.'));
    }

    public function delete(User $user, string $id): void
    {
        $conversation = $this->current($user, $id) ?? throw new NotFoundHttpException();
        $this->store->delete(UuidV7::fromString($id));
        $this->conversations->remove($conversation);
        try {
            $this->files->removeConversation($id);
        } catch (\Throwable) {
            $this->logger->warning('Conversation chart cleanup unavailable.', ['conversation_id' => $id]);
        }
    }

    public function favourite(User $user, string $id, bool $favourite): ChatConversation
    {
        $conversation = $this->current($user, $id) ?? throw new NotFoundHttpException();
        $conversation->favourite = $favourite;
        $this->conversations->save($conversation);

        return $conversation;
    }

    public function submit(User $user, string $question, string $id = ''): ChatConversation
    {
        if (null === $user->getChatGptIdentity()) {
            throw new \DomainException('Connect ChatGPT before sending a message.');
        }
        $input = '' === $id ? new MessageBag() : MessageBag::forId(UuidV7::fromString($id));
        $conversation = '' === $id ? new ChatConversation($user, $input->getId()->toRfc4122()) : $this->current($user, $id);
        \assert(null !== $conversation);
        if ('' === $id) {
            $conversation->label = $question;
            $this->conversations->save($conversation);
        }
        if (0 === $this->store->load($input->getId())->count()) {
            // Initial reference context stays native user content, not a second application transcript.
            $input = $input->with(Message::ofUser($this->context->forOwner($user)));
        }
        // Native message IDs survive Messenger redelivery; Agent owns continuation and conflicts.
        $input = $input->with(Message::ofUser($question));
        $this->bus->dispatch(new GenerateChat($conversation->id, $user->getId() ?? throw new \LogicException('Owner unavailable.'), $input));

        return $conversation;
    }
}
