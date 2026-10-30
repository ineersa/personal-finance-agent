<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class Source extends UserRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, Statement> */
    #[ORM\OneToMany(targetEntity: Statement::class, mappedBy: 'source')]
    private Collection $statements;

    public function __construct(
        User $user,
        #[ORM\Column(length: 255)]
        public string $name,
        #[ORM\Column(length: 255, nullable: true)]
        public ?string $description = null,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $updatedAt = new \DateTimeImmutable(),
    ) {
        parent::__construct($user);
        $this->statements = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** @return Collection<int, Statement> */
    public function getStatements(): Collection
    {
        return $this->statements;
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
