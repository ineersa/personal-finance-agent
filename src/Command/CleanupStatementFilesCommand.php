<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\ImportLifecycle;
use App\Storage\StatementFileCleanup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:statement:cleanup', description: 'Retry committed statement file removals; never delete files still referenced by statements.')]
final readonly class CleanupStatementFilesCommand
{
    public function __construct(private StatementFileCleanup $cleanup, private ImportLifecycle $lifecycle)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $lock = $this->lifecycle->acquire(true);
        try {
            $this->cleanup->drain();
        } catch (\Throwable) {
            $io->error('File cleanup is incomplete. Pending filenames are retained. Check private statement storage availability and retry.');

            return Command::FAILURE;
        } finally {
            $lock->release();
        }
        $io->success('Unreferenced pending statement files were removed. Referenced files were retained.');

        return Command::SUCCESS;
    }
}
