<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Source;
use App\Entity\Statement;
use App\Entity\User;
use App\Enum\ProcessingStatus;
use App\Message\ProcessStatement;
use App\Storage\StatementStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class StatementUpload
{
    public function __construct(private StatementStorage $storage, private EntityManagerInterface $entityManager, private MessageBusInterface $bus)
    {
    }

    public function upload(User $owner, Source $source, UploadedFile $file): Statement
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
        try {
            $id = $statement->getId() ?? throw new \LogicException('Statement ID missing after persistence.');
            $this->bus->dispatch(new ProcessStatement($id, $owner->getId()));
        } catch (\Throwable) {
            // Publication is outside the database transaction; retained owned metadata exposes the failure.
            $statement->processingStatus = ProcessingStatus::Failed;
            $statement->processingError = 'The processing queue was unavailable. Retry processing when the broker is running.';
            $this->entityManager->flush();
        }

        return $statement;
    }
}
