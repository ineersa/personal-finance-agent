<?php

declare(strict_types=1);

namespace App\Finance\Entity;

use App\Enum\InstrumentKind;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class Source extends FinanceRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, Statement> */
    #[ORM\OneToMany(targetEntity: Statement::class, mappedBy: 'source')]
    private Collection $statements;

    public function __construct(
        #[ORM\Column(length: 255)]
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,
        #[ORM\Column(length: 255, nullable: true)]
        #[Assert\Length(max: 255)]
        public ?string $description = null,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $updatedAt = new \DateTimeImmutable(),
        #[ORM\Column(length: 3, nullable: true)]
        #[Assert\Regex(pattern: '/\A[A-Z]{3}\z/D')]
        public ?string $currency = null,
        #[ORM\Column(enumType: InstrumentKind::class, options: ['default' => 'other'])]
        public InstrumentKind $instrumentKind = InstrumentKind::Other,
    ) {
        $this->statements = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
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
