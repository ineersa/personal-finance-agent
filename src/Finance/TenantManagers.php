<?php

declare(strict_types=1);

namespace App\Finance;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

class TenantManagers implements ResetInterface
{
    private ?EntityManagerInterface $requestManager = null;

    public function __construct(
        #[Autowire(service: 'doctrine.orm.finance_configuration')] private readonly Configuration $configuration,
        #[Autowire(service: 'doctrine.dbal.finance_connection')] private readonly Connection $prototype,
        #[Autowire(service: 'doctrine.dbal.default_connection')] private readonly Connection $central,
        private readonly Security $security,
        private readonly RequestStack $requests,
    ) {
    }

    public function path(User $owner): string
    {
        if (null === $owner->getId()) {
            throw new \LogicException('A persisted user is required to identify tenant storage.');
        }
        $path = $this->central->getParams()['path'] ?? null;
        if (!\is_string($path) || '' === $path) {
            throw new \LogicException('Tenant storage requires a file-backed central database.');
        }

        return \dirname($path).'/tenants/'.$owner->getId().'.db';
    }

    public function open(User $owner, bool $provision = false): EntityManagerInterface
    {
        if (\in_array('ROLE_ADMIN', $owner->getRoles(), true)) {
            throw new \LogicException('Administrators cannot open tenant finance storage.');
        }
        $path = $this->path($owner);
        if (is_link(\dirname($path)) || is_link($path)) {
            throw new \RuntimeException('Refusing symlinked tenant storage.');
        }
        if (!$provision && !is_file($path)) {
            throw new \RuntimeException('Tenant database has not been provisioned.');
        }
        if ($provision && !is_dir(\dirname($path)) && !mkdir(\dirname($path), 0700, true) && !is_dir(\dirname($path))) {
            throw new \RuntimeException('Cannot create private tenant storage.');
        }
        $params = $this->prototype->getParams();
        unset($params['url'], $params['memory']);
        $params['path'] = $path;
        if (isset($params['dama.connection_key'])) {
            $params['dama.connection_key'] .= ':'.$path;
        }
        $connection = DriverManager::getConnection($params, $this->prototype->getConfiguration());

        return new EntityManager($connection, $this->configuration);
    }

    public function current(): EntityManagerInterface
    {
        // Console schema tooling uses only its explicitly configured diagnostic connection.
        // Background handlers open their own owner-bound manager instead of using this service.
        if (null === $this->requests->getMainRequest()) {
            return new EntityManager($this->prototype, $this->configuration);
        }
        if (null !== $this->requestManager) {
            return $this->requestManager;
        }
        $owner = $this->security->getUser();
        if (!$owner instanceof User || \in_array('ROLE_ADMIN', $owner->getRoles(), true)) {
            // Doctrine's profiler reads every manager's metadata/UoW on public pages.
            // Keep that inspection possible, but deny connecting to any database.
            $configuration = clone $this->prototype->getConfiguration();
            $configuration->setMiddlewares([...$configuration->getMiddlewares(), new RejectTenantConnectionMiddleware()]);
            $params = $this->prototype->getParams();
            unset($params['url'], $params['path'], $params['memory']);

            return new EntityManager(DriverManager::getConnection($params, $configuration), $this->configuration);
        }

        return $this->requestManager = $this->open($owner);
    }

    public function reset(): void
    {
        if (null !== $this->requestManager) {
            $this->release($this->requestManager);
            $this->requestManager = null;
        }
    }

    public function release(EntityManagerInterface $manager): void
    {
        $manager->clear();
        $manager->getConnection()->close();
    }
}
