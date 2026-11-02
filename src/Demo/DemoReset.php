<?php

declare(strict_types=1);

namespace App\Demo;

use App\Entity\Statement;
use App\Entity\User;
use App\Import\ImportException;
use App\Import\ImportLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\LoaderInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpKernel\KernelInterface;

final readonly class DemoReset
{
    public function __construct(private EntityManagerInterface $em, private ImportLifecycle $lifecycle, private KernelInterface $kernel, #[Target('statements.storage')] private FilesystemOperator $storage, #[Autowire('%env(bool:LOCAL_DEMO_RESET_ENABLED)%')] private bool $enabled, private ?LoaderInterface $loader = null)
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
            // Native Alice purges mapped tables and reloads only the project's canonical fixtures.
            $this->em->clear();
            $this->loader->load(new Application($this->kernel), $this->em, [], $this->kernel->getEnvironment(), false, false, true);
            $retained = [];
            foreach ($this->em->getRepository(Statement::class)->findAll() as $statement) {
                $retained[$statement->storedFilename] = true;
            }
            // List only this private adapter's root. Never traverse directories or touch env/keys.
            foreach ($this->storage->listContents('', false) as $entry) {
                if ($entry->isFile() && !isset($retained[$entry->path()])) {
                    $this->storage->delete($entry->path());
                }
            }
        } finally {
            $lock->release();
        }
    }
}
