<?php

declare(strict_types=1);

namespace App\Finance;

use App\Entity\User;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final readonly class TenantSchema
{
    private const string LATEST = 'latest';

    public function __construct(private TenantManagers $tenants, private InitialReferenceData $reference, #[Autowire('%kernel.project_dir%')] private string $root, private Filesystem $filesystem)
    {
    }

    public function provision(User $owner, bool $seed = true): EntityManagerInterface
    {
        $path = $this->tenants->path($owner);
        $new = !file_exists($path) && !is_link($path);
        $em = null;
        try {
            $em = $this->tenants->open($owner, true);
            $factory = DependencyFactory::fromEntityManager(new ConfigurationArray([
                'migrations_paths' => ['App\\Finance\\Migrations' => $this->root.'/src/Finance/Migrations'],
            ]), new ExistingEntityManager($em));
            $factory->getMetadataStorage()->ensureInitialized();
            $version = $factory->getVersionAliasResolver()->resolveVersionAlias(self::LATEST);
            $factory->getMigrator()->migrate($factory->getMigrationPlanCalculator()->getPlanUntilVersion($version), new MigratorConfiguration());
            $this->filesystem->chmod($path, 0600);
            if ($new && $seed) {
                $this->reference->seed($em);
            }

            return $em;
        } catch (\Throwable $error) {
            try {
                if (null !== $em) {
                    $this->tenants->release($em);
                }
            } finally {
                if ($new) {
                    foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
                        if (is_link($file) || is_link(\dirname($file)) || is_dir($file)) {
                            throw new \RuntimeException('Refusing symlinked tenant storage.', previous: $error);
                        }
                    }
                    $this->filesystem->remove([$path, $path.'-wal', $path.'-shm']);
                }
            }
            throw $error;
        }
    }
}
