<?php

declare(strict_types=1);

namespace App\Finance\Entity;

use App\Repository\CategoryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['comment' => 'Primary key within this tenant database.'])]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 255, options: ['comment' => 'Category display name.'])]
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,
        #[ORM\Column(type: 'text', nullable: true, options: ['comment' => 'Optional category explanation.'])]
        public ?string $description = null,
        #[ORM\Column(length: 255, nullable: true, options: ['comment' => 'Optional icon identifier.'])]
        #[Assert\Length(max: 255)]
        public ?string $icon = null,
        #[ORM\Column(type: 'datetime_immutable', nullable: true, options: ['comment' => 'Category creation timestamp.'])]
        public ?\DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        #[ORM\Column(type: 'datetime_immutable', nullable: true, options: ['comment' => 'Category last-update timestamp.'])]
        public ?\DateTimeImmutable $updatedAt = new \DateTimeImmutable(),
    ) {
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
