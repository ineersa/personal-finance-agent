<?php

declare(strict_types=1);

namespace App\Demo;

use App\Entity\User;
use App\Import\ImportException;
use App\Import\ImportLifecycle;
use App\Storage\StatementFileCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\LoaderInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpKernel\KernelInterface;

final readonly class DemoReset
{
    public function __construct(private EntityManagerInterface $em, private ImportLifecycle $lifecycle, private KernelInterface $kernel, #[Target('statements.storage')] private FilesystemOperator $storage, private StatementFileCleanup $cleanup, #[Autowire('%env(bool:LOCAL_DEMO_RESET_ENABLED)%')] private bool $enabled, private ?LoaderInterface $loader = null)
    {
    }

    public function isAllowed(User $user): bool
    {
        return $this->enabled && \in_array($this->kernel->getEnvironment(), ['dev', 'test'], true) && 'demo@test.com' === $user->getUserIdentifier() && !\in_array('ROLE_ADMIN', $user->getRoles(), true) && null !== $this->loader;
    }

    public function reset(): void
    {
        if (!$this->enabled || !\in_array($this->kernel->getEnvironment(), ['dev', 'test'], true) || null === $this->loader) {
            throw new \LogicException('Local demo reset is disabled.');
        }
        $lock = $this->lifecycle->acquire(true);
        try {
            if (0 !== (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM statement WHERE processing_status IN ('queued', 'running')")) {
                throw new ImportException('Reset refused: an import is queued or running. Wait for every import to finish.');
            }
            // Preserve cleanup intent even if Alice fails; drain skips still-referenced files.
            foreach ($this->storage->listContents('', false) as $entry) {
                if ($entry->isFile()) {
                    $this->cleanup->schedule($entry->path());
                }
            }
            $this->em->flush();
            // Native Alice purges financial tables, retaining pending cleanup for explicit retry.
            $this->em->clear();
            $this->loader->load(new Application($this->kernel), $this->em, [], $this->kernel->getEnvironment(), false, false, true);
            $this->cleanup->drain();
        } finally {
            $lock->release();
        }
    }
}
