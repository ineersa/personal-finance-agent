<?php

declare(strict_types=1);

namespace App\Storage;

use App\Entity\PendingStatementFileCleanup;
use App\Entity\Statement;
use Doctrine\ORM\EntityManagerInterface;

final readonly class StatementFileCleanup
{
    public function __construct(private EntityManagerInterface $em, private StatementStorage $storage)
    {
    }

    public function schedule(string $filename): void
    {
        if (null === $this->em->getRepository(PendingStatementFileCleanup::class)->findOneBy(['filename' => $filename])) {
            $this->em->persist(new PendingStatementFileCleanup($filename));
        }
    }

    public function drain(): void
    {
        foreach ($this->em->getRepository(PendingStatementFileCleanup::class)->findAll() as $pending) {
            // Reset may fail before removing metadata, or canonical fixtures may reuse a filename.
            if (0 !== $this->em->getRepository(Statement::class)->count(['storedFilename' => $pending->filename])) {
                continue;
            }
            // Bytes are deleted only after the transaction scheduling cleanup has committed.
            $this->storage->delete($pending->filename);
            $this->em->remove($pending);
            $this->em->flush();
        }
    }
}
