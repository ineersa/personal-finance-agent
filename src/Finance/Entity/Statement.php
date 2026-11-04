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
    #[ORM\Column(options: ['comment' => 'Primary key within this tenant database.'])]
    private ?int $id = null;

    /** @var Collection<int, Transaction> */
    #[ORM\OneToMany(targetEntity: Transaction::class, mappedBy: 'statement')]
    private Collection $transactions;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'statements')]
        #[ORM\JoinColumn(nullable: false, options: ['comment' => 'Account or card from which this statement was imported.'])]
        public Source $source,
        #[ORM\Column(length: 255, options: ['comment' => 'Private statement storage filename, not a public URL.'])]
        public string $storedFilename,
        #[ORM\Column(length: 255, options: ['comment' => 'Original upload filename; untrusted text, not a transaction date.'])]
        public string $originalFilename,
        #[ORM\Column(length: 100, options: ['comment' => 'Uploaded statement media type.'])]
        public string $mediaType,
        #[ORM\Column(options: ['comment' => 'Uploaded statement size in bytes.'])]
        public int $byteSize,
        #[ORM\Column(length: 64, options: ['comment' => 'SHA-256 digest of uploaded statement bytes.'])]
        public string $sha256,
        #[ORM\Column(type: 'datetime_immutable', options: ['comment' => 'Statement upload timestamp.'])]
        public \DateTimeImmutable $uploadedAt = new \DateTimeImmutable(),
        #[ORM\Column(type: 'datetime_immutable', nullable: true, options: ['comment' => 'Import completion timestamp, when available.'])]
        public ?\DateTimeImmutable $processedAt = null,
        #[ORM\Column(type: 'date_immutable', nullable: true, options: ['comment' => 'Explicit statement date or earliest valid imported transaction date; NULL if unknown.'])]
        public ?\DateTimeImmutable $statementDate = null,
        #[ORM\Column(enumType: ProcessingStatus::class, options: ['comment' => 'Import processing state.', 'default' => 'completed'])]
        public ProcessingStatus $processingStatus = ProcessingStatus::Completed,
        #[ORM\Column(type: 'text', nullable: true, options: ['comment' => 'Optional import processing error; untrusted text.'])]
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
