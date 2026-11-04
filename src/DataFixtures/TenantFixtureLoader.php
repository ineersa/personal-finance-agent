<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use App\Finance\InitialReferenceData;
use App\Finance\TenantSchema;
use Doctrine\ORM\EntityManagerInterface;
use Fidry\AliceDataFixtures\Bridge\Doctrine\Persister\ObjectManagerPersister;
use Fidry\AliceDataFixtures\Bridge\Doctrine\Purger\Purger;
use Fidry\AliceDataFixtures\LoaderInterface as DataLoader;
use Fidry\AliceDataFixtures\Persistence\PersisterAwareInterface;
use Fidry\AliceDataFixtures\Persistence\PurgeMode;
use Hautelook\AliceBundle\LoaderInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class TenantFixtureLoader implements LoaderInterface
{
    public function __construct(private LoaderInterface $inner, #[Autowire(service: 'hautelook_alice.data_fixtures.append_loader')] private DataLoader&PersisterAwareInterface $loader, private TenantSchema $schema, private InitialReferenceData $reference, #[Autowire('%kernel.project_dir%')] private string $root)
    {
    }

    /** @param string[] $bundles
     * @return object[]
     */
    public function load(Application $application, EntityManagerInterface $manager, array $bundles, string $environment, bool $append, bool $purgeWithTruncate, bool $noBundles = false): array
    {
        $fixtures = $this->inner->load($application, $manager, $bundles, $environment, $append, $purgeWithTruncate, $noBundles);
        $tenantFixtures = [];
        foreach ($fixtures as $owner) {
            if (!$owner instanceof User || \in_array('ROLE_ADMIN', $owner->getRoles(), true)) {
                continue;
            }
            $em = $this->schema->provision($owner, false);
            try {
                if (!$append) {
                    (new Purger($em, PurgeMode::createDeleteMode()))->purge();
                }
                $files = [$this->root.'/fixtures/category.yaml'];
                if ('demo@test.com' === $owner->getUserIdentifier()) {
                    foreach (['source', 'statement', 'transaction'] as $name) {
                        $files[] = $this->root.'/fixtures/'.$name.'.yaml';
                    }
                }
                $loaded = $this->loader->withPersister(new ObjectManagerPersister($em))->load($files, $application->getKernel()->getContainer()->getParameterBag()->all());
                $this->reference->seedRates($em);
                $tenantFixtures += $loaded;
            } finally {
                $em->clear();
                $em->getConnection()->close();
            }
        }

        return $fixtures + $tenantFixtures;
    }
}
