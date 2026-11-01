<?php

declare(strict_types=1);

namespace App\Storage;

use App\Entity\Source;
use App\Entity\Statement;
use App\Entity\User;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class StatementStorage
{
    public function __construct(#[Target('statements.storage')] private FilesystemOperator $storage)
    {
    }

    // Call only after upload validation/admission, outside a database write transaction.
    public function store(User $owner, Source $source, File $file, string $originalFilename): Statement
    {
        $this->assertOwner($owner, $source->getUser());
        $key = bin2hex(random_bytes(16));
        $size = $file->getSize();
        $hash = hash_file('sha256', $file->getPathname());
        if (false === $size || false === $hash) {
            throw new \RuntimeException('Cannot inspect statement file.');
        }
        $statement = new Statement($owner, $source, $key, $originalFilename, $file->getMimeType() ?? 'application/octet-stream', $size, $hash);
        $stream = fopen($file->getPathname(), 'rb');
        if (false === $stream) {
            throw new \RuntimeException('Cannot open statement file.');
        }
        try {
            $this->storage->writeStream($key, $stream);
        } catch (\Throwable $error) {
            // Delete a possible partial write. A cleanup failure must remain visible.
            $this->storage->delete($key);
            throw $error;
        } finally {
            fclose($stream);
        }

        return $statement;
    }

    /** @return resource */
    public function read(User $owner, Statement $statement)
    {
        return $this->storage->readStream($this->authorizedKey($owner, $statement));
    }

    // Also compensates a failed metadata commit after store(). No entity callbacks.
    public function delete(User $owner, Statement $statement): void
    {
        $this->storage->delete($this->authorizedKey($owner, $statement));
    }

    // Enforce ownership and key format for HTTP callers and workers alike.
    private function authorizedKey(User $owner, Statement $statement): string
    {
        $this->assertOwner($owner, $statement->getUser());
        $this->assertOwner($owner, $statement->source->getUser());
        if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $statement->storageKey)) {
            throw new \InvalidArgumentException('Invalid statement storage key.');
        }

        return $statement->storageKey;
    }

    private function assertOwner(User $owner, User $recordOwner): void
    {
        if (null === $owner->getId() || $owner->getId() !== $recordOwner->getId() || \in_array('ROLE_ADMIN', $owner->getRoles(), true)) {
            throw new AccessDeniedException();
        }
    }
}
