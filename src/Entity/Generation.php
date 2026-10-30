<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GenerationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GenerationRepository::class)]
class Generation extends OwnedRecord
{
    public function __construct(
        User $owner,
        #[ORM\Column(length: 64, unique: true)]
        public string $operationKey,
        #[ORM\Column(length: 32)]
        public string $purpose,
        #[ORM\Column(length: 64)]
        public string $provider,
        #[ORM\Column(length: 255)]
        public string $model,
        #[ORM\Column(length: 32)]
        public string $state = 'queued',
        #[ORM\Column(length: 64, nullable: true)]
        public ?string $reasoning = null,
        #[ORM\ManyToOne]
        public ?Conversation $conversation = null,
        #[ORM\ManyToOne]
        public ?Statement $statement = null,
        #[ORM\Column(type: 'datetime_immutable')]
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        parent::__construct($owner);
    }
}
