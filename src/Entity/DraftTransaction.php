<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DraftTransactionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DraftTransactionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_draft_statement_row', columns: ['statement_id', 'row_number'])]
class DraftTransaction extends OwnedRecord
{
    public function __construct(
        User $owner,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        public Statement $statement,
        #[ORM\Column]
        public int $rowNumber,
        #[ORM\Column(type: 'date_immutable', nullable: true)]
        public ?\DateTimeImmutable $transactionDate = null,
        #[ORM\Column(type: 'text', nullable: true)]
        public ?string $amount = null,
        #[ORM\Column(length: 3, nullable: true)]
        public ?string $currency = null,
        #[ORM\Column(enumType: \App\Enum\Direction::class, nullable: true)]
        public ?\App\Enum\Direction $direction = null,
        #[ORM\Column(type: 'text')]
        public string $description = '',
        #[ORM\ManyToOne]
        public ?Category $category = null,
    ) {
        parent::__construct($owner);
    }
}
