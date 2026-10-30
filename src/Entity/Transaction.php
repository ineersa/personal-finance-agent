<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Direction;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'financial_transaction')]
class Transaction extends UserRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        User $user,
        #[ORM\ManyToOne(inversedBy: 'transactions')]
        #[ORM\JoinColumn(nullable: false)]
        public Statement $statement,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        public Source $source,
        #[ORM\Column(type: 'date_immutable', nullable: true)]
        #[Assert\NotNull(groups: ['approved'])]
        public ?\DateTimeImmutable $transactionDate = null,
        #[ORM\Column(type: 'text', nullable: true)]
        #[Assert\NotBlank(groups: ['approved'])]
        #[Assert\Regex(pattern: '/\A[+-]?[0-9]+(?:\.[0-9]+)?\z/D', groups: ['approved'])]
        public ?string $amount = null,
        #[ORM\Column(length: 3, nullable: true)]
        #[Assert\NotBlank(groups: ['approved'])]
        #[Assert\Regex(pattern: '/\A[A-Z]{3}\z/D', groups: ['approved'])]
        public ?string $currency = null,
        #[ORM\Column(enumType: Direction::class, nullable: true)]
        #[Assert\NotNull(groups: ['approved'])]
        public ?Direction $direction = null,
        #[ORM\Column(type: 'text')]
        public string $description = '',
        #[ORM\ManyToOne]
        public ?Category $category = null,
        #[ORM\Column]
        public bool $isDraft = true,
    ) {
        parent::__construct($user);
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
