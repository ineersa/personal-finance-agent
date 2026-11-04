<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Finance\TenantManagers;
use App\Finance\TenantSchema;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:tenant:migrate', description: 'Provision missing tenant databases and apply the finance migration history to every ordinary user.')]
final class MigrateTenantsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $central, private readonly TenantSchema $schema, private readonly TenantManagers $tenants)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = 0;
        foreach ($this->central->getRepository(User::class)->findAll() as $owner) {
            if (\in_array('ROLE_ADMIN', $owner->getRoles(), true)) {
                continue;
            }
            $em = $this->schema->provision($owner);
            $this->tenants->release($em);
            ++$count;
        }
        $output->writeln(\sprintf('Migrated %d tenant databases.', $count));

        return Command::SUCCESS;
    }
}
