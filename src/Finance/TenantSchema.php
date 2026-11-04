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

final readonly class TenantSchema
{
    private const string LATEST = 'latest';

    public function __construct(private TenantManagers $tenants, private InitialReferenceData $reference, #[Autowire('%kernel.project_dir%')] private string $root)
    {
    }

    public function provision(User $owner, bool $seed = true): EntityManagerInterface
    {
        $new = !is_file($this->tenants->path($owner));
        $em = $this->tenants->open($owner, true);
        $factory = DependencyFactory::fromEntityManager(new ConfigurationArray([
            'migrations_paths' => ['App\\Finance\\Migrations' => $this->root.'/src/Finance/Migrations'],
        ]), new ExistingEntityManager($em));
        $factory->getMetadataStorage()->ensureInitialized();
        $version = $factory->getVersionAliasResolver()->resolveVersionAlias(self::LATEST);
        $factory->getMigrator()->migrate($factory->getMigrationPlanCalculator()->getPlanUntilVersion($version), new MigratorConfiguration());
        chmod($this->tenants->path($owner), 0600);
        if ($new && $seed) {
            $this->reference->seed($em);
        }

        return $em;
    }
}
