<?php

declare(strict_types=1);

namespace App\Import;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\FlockStore;

// Shared across the checkout's web and worker containers, outside statement cleanup.
final readonly class ImportLifecycle
{
    private LockFactory $factory;

    public function __construct(#[Autowire('%statement_storage_directory%')] string $directory)
    {
        $this->factory = new LockFactory(new FlockStore(\dirname($directory).'/import-locks'));
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
