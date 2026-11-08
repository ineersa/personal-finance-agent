<?php

declare(strict_types=1);

namespace App\Entity;

use App\Chat\ChatModel;
use App\Chat\ReasoningEffort;
use App\Repository\ChatConversationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/** Application metadata only. Symfony AI owns messages under the same native ID. */
#[ORM\Entity(repositoryClass: ChatConversationRepository::class)]
#[ORM\Table(name: 'chat_metadata')]
class ChatConversation
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;

    #[ORM\Column(type: 'text')]
    public string $label = 'New chat';

    #[ORM\Column]
    public bool $favourite = false;

    #[ORM\Column(nullable: true, enumType: ChatModel::class)]
    public ?ChatModel $model = null;

    #[ORM\Column(nullable: true, enumType: ReasoningEffort::class)]
    public ?ReasoningEffort $effort = null;

    /** @var list<array{message: string, file: string}> */
    #[ORM\Column(type: 'json')]
    public array $charts = [];

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private readonly User $user,
        string $conversationId,
    ) {
        $this->id = $conversationId;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return UuidV7::fromString($this->id)->getDateTime();
    }
}
