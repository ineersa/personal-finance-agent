<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConversationMessageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ConversationMessageRepository::class)]
class ConversationMessage extends OwnedRecord
{
    public function __construct(
        User $owner,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        public Conversation $conversation,
        #[ORM\Column(length: 32)]
        public string $role,
        #[ORM\Column(type: 'text')]
        public string $content,
        #[ORM\Column(type: 'datetime_immutable')]
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        parent::__construct($owner);
    }
}
