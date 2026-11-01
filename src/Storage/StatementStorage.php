<?php

declare(strict_types=1);

namespace App\Storage;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Uid\Uuid;

final readonly class StatementStorage
{
    public function __construct(#[Target('statements.storage')] private FilesystemOperator $storage)
    {
    }

    public function store(File $file): string
    {
        $filename = Uuid::v4()->toRfc4122();
        $stream = fopen($file->getPathname(), 'rb');
        if (false === $stream) {
            throw new \RuntimeException('Cannot open statement file.');
        }
        try {
            $this->storage->writeStream($filename, $stream);
        } catch (\Throwable $error) {
            // Delete a possible partial write. A cleanup failure must remain visible.
            $this->storage->delete($filename);
            throw $error;
        } finally {
            fclose($stream);
        }

        return $filename;
    }

    /** @return resource */
    public function read(string $filename)
    {
        return $this->storage->readStream($filename);
    }

    public function delete(string $filename): void
    {
        $this->storage->delete($filename);
    }
}
