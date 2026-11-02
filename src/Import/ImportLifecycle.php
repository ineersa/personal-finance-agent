<?php

declare(strict_types=1);

namespace App\Import;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

// Shared across the checkout's web and worker containers, outside statement cleanup.
final readonly class ImportLifecycle
{
    public function __construct(#[Target('statement_lifecycle')] private LockFactory $factory)
    {
    }

    public function acquire(bool $exclusive = false): SharedLockInterface
    {
        $lock = $this->factory->createLock('statement-lifecycle', null);
        if (!($exclusive ? $lock->acquire() : $lock->acquireRead())) {
            throw new ImportException('Statement maintenance is busy. Wait for processing or maintenance to finish, then try again.');
        }

        return $lock;
    }
}
