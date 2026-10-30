<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StatementRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StatementRepository::class)]
class Statement extends OwnedRecord
{
    public function __construct(
        User $owner,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        public Source $source,
        #[ORM\Column(length: 255, unique: true)]
        public string $storageKey,
        #[ORM\Column(length: 255)]
        public string $originalFilename,
        #[ORM\Column(length: 100)]
        public string $mediaType,
        #[ORM\Column]
        public int $byteSize,
        #[ORM\Column(length: 64)]
        public string $sha256,
        #[ORM\Column(type: 'datetime_immutable')]
        public \DateTimeImmutable $uploadedAt = new \DateTimeImmutable(),
    ) {
        parent::__construct($owner);
    }
}
