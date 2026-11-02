<?php

declare(strict_types=1);

namespace App\Demo;

use App\Entity\Statement;
use App\Entity\User;
use App\Import\ImportException;
use App\Repository\StatementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\LoaderInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpKernel\KernelInterface;

final readonly class DemoReset
{
    public function __construct(private EntityManagerInterface $em, private StatementRepository $statements, private KernelInterface $kernel, #[Target('statements.storage')] private FilesystemOperator $storage, #[Autowire('%env(bool:LOCAL_DEMO_RESET_ENABLED)%')] private bool $enabled, private ?LoaderInterface $loader = null)
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
        if ($this->statements->hasActiveImports()) {
            throw new ImportException('Reset refused: an import is queued or running. Wait for every import to finish.');
        }
        $this->em->clear();
        $this->loader->load(new Application($this->kernel), $this->em, [], $this->kernel->getEnvironment(), false, false, true);
        $filenames = array_map(static fn (Statement $statement): string => $statement->storedFilename, $this->em->getRepository(Statement::class)->findAll());
        try {
            foreach ($this->storage->listContents('', false) as $entry) {
                if ($entry->isFile() && !\in_array($entry->path(), $filenames, true)) {
                    $this->storage->delete($entry->path());
                }
            }
        } catch (\Throwable) {
            throw new ImportException('Demo records were restored, but some uploaded files could not be removed. Orphaned bytes may remain in private storage.');
        }
    }
}
