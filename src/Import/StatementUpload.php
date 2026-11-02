<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Source;
use App\Entity\Statement;
use App\Entity\User;
use App\Enum\ProcessingStatus;
use App\Message\ProcessStatementMessage;
use App\Repository\StatementRepository;
use App\Storage\StatementStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class StatementUpload
{
    public function __construct(private StatementStorage $storage, private EntityManagerInterface $entityManager, private MessageBusInterface $bus, private ImportLifecycle $lifecycle, private StatementRepository $statements)
    {
    }

    public function upload(User $owner, Source $source, UploadedFile $file): Statement
    {
        $lock = $this->lifecycle->acquire();
        try {
            return $this->storeAndDispatch($owner, $source, $file);
        } finally {
            $lock->release();
        }
    }

    public function retry(User $owner, Statement $statement): void
    {
        $lock = $this->lifecycle->acquire();
        try {
            if (\in_array('ROLE_ADMIN', $owner->getRoles(), true) || $statement->getUser()->getId() !== $owner->getId() || $statement->source->getUser()->getId() !== $owner->getId()) {
                throw new ImportException('The statement is not available.');
            }
            if ($this->statements->transition($statement->getId() ?? throw new \LogicException('Statement missing.'), $owner->getId() ?? throw new \LogicException('Owner missing.'), $statement->storedFilename, ProcessingStatus::Failed, ProcessingStatus::Queued, withoutTransactions: true)) {
                $this->dispatch($statement, $owner);
            }
        } finally {
            $lock->release();
        }
    }

    private function storeAndDispatch(User $owner, Source $source, UploadedFile $file): Statement
    {
        if (null === $owner->getId() || null === $source->getId() || $source->getUser()->getId() !== $owner->getId() || \in_array('ROLE_ADMIN', $owner->getRoles(), true)) {
            throw new ImportException('The selected source is not available.');
        }
        if (null === $source->currency) {
            throw new ImportException('Set the source currency before uploading a statement.');
        }
        if (!$file->isValid() || $file->getSize() <= 0 || $file->getSize() > 10 * 1024 * 1024 || 'csv' !== strtolower($file->getClientOriginalExtension())) {
            throw new ImportException('Upload a non-empty CSV of at most 10 MiB.');
        }
        $sha256 = hash_file('sha256', $file->getPathname());
        if (false === $sha256) {
            throw new ImportException('Cannot read uploaded statement metadata.');
        }
        $originalFilename = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $mediaType = $file->getMimeType() ?? 'text/plain';
        $byteSize = $file->getSize();
        try {
            $filename = $this->storage->store($file);
        } catch (\Throwable) {
            throw new ImportException('Statement storage failed. No processing job was created.');
        }
        $statement = new Statement($owner, $source, $filename, $originalFilename, $mediaType, $byteSize, $sha256, processingStatus: ProcessingStatus::Queued);
        try {
            // Only metadata persistence is inside Doctrine's short flush transaction.
            $this->entityManager->persist($statement);
            $this->entityManager->flush();
        } catch (\Throwable) {
            try {
                $this->storage->delete($filename);
            } catch (\Throwable) {
                throw new ImportException('Metadata persistence failed and statement cleanup requires operator attention. No processing job was created.');
            }
            throw new ImportException('Statement metadata could not be saved. Stored bytes were removed; no processing job was created.');
        }
        $this->dispatch($statement, $owner);

        return $statement;
    }

    private function dispatch(Statement $statement, User $owner): void
    {
        $id = $statement->getId() ?? throw new \LogicException('Statement missing.');
        $ownerId = $owner->getId() ?? throw new \LogicException('Owner missing.');
        try {
            $this->bus->dispatch(new ProcessStatementMessage($id, $ownerId, $statement->storedFilename));
        } catch (\Throwable) {
            // A worker may already have claimed or completed this import.
            $this->statements->transition($id, $ownerId, $statement->storedFilename, ProcessingStatus::Queued, ProcessingStatus::Failed, 'The processing queue is unavailable. Retry when the broker is running.');
            $this->entityManager->refresh($statement);
        }
    }
}
