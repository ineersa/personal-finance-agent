<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TransactionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Table(name: 'approved_transaction')]
class Transaction extends OwnedRecord
{
    public function __construct(
        User $owner,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        public Statement $statement,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        public Source $source,
        #[ORM\Column(type: 'date_immutable')]
        public \DateTimeImmutable $transactionDate,
        #[ORM\Column(type: 'exact_decimal')]
        public string $amount,
        #[ORM\Column(length: 3)]
        public string $currency,
        #[ORM\Column(enumType: \App\Enum\Direction::class)]
        public \App\Enum\Direction $direction,
        #[ORM\Column(type: 'text')]
        public string $description,
        #[ORM\ManyToOne]
        public ?Category $category = null,
    ) {
        parent::__construct($owner);
    }
}
