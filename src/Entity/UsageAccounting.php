<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UsageAccountingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UsageAccountingRepository::class)]
class UsageAccounting extends OwnedRecord
{
    public function __construct(
        User $owner,
        #[ORM\Column(length: 64, unique: true)]
        public string $attemptKey,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        public Generation $generation,
        #[ORM\Column]
        public int $inputTokens = 0,
        #[ORM\Column]
        public int $outputTokens = 0,
        #[ORM\Column(type: 'exact_decimal', nullable: true)]
        public ?string $cost = null,
        #[ORM\Column(type: 'datetime_immutable')]
        public \DateTimeImmutable $recordedAt = new \DateTimeImmutable(),
    ) {
        parent::__construct($owner);
    }
}
