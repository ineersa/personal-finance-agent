<?php

declare(strict_types=1);

namespace App\Finance\Entity;

use App\Enum\ProcessingStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'UNIQ_STATEMENT_STORED_FILENAME', columns: ['stored_filename'])]
class Statement extends FinanceRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, Transaction> */
    #[ORM\OneToMany(targetEntity: Transaction::class, mappedBy: 'statement')]
    private Collection $transactions;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'statements')]
        #[ORM\JoinColumn(nullable: false)]
        public Source $source,
        #[ORM\Column(length: 255)]
        public string $storedFilename,
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
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $processedAt = null,
        #[ORM\Column(type: 'date_immutable', nullable: true)]
        public ?\DateTimeImmutable $statementDate = null,
        #[ORM\Column(enumType: ProcessingStatus::class, options: ['default' => 'completed'])]
        public ProcessingStatus $processingStatus = ProcessingStatus::Completed,
        #[ORM\Column(type: 'text', nullable: true)]
        public ?string $processingError = null,
    ) {
        $this->transactions = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->originalFilename;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** @return Collection<int, Transaction> */
    public function getTransactions(): Collection
    {
        return $this->transactions;
    }
}
