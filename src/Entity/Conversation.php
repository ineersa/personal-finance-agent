<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConversationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ConversationRepository::class)]
class Conversation extends OwnedRecord
{
    public function __construct(
        User $owner,
        #[ORM\Column(length: 255)]
        public string $title = '',
        #[ORM\Column]
        public bool $saved = false,
        #[ORM\Column(type: 'datetime_immutable')]
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        parent::__construct($owner);
    }
}
