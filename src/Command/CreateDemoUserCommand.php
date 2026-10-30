<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:demo:user', description: 'Create one synthetic dev/test account with a random password; never overwrite an account.')]
final class CreateDemoUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Synthetic email ending in @example.test')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant ROLE_ADMIN to this new account');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getArgument('email');
        if (!\in_array($this->environment, ['dev', 'test'], true)) {
            $output->writeln('<error>Synthetic accounts are allowed only in dev or test.</error>');

            return Command::FAILURE;
        }
        if (!\is_string($email) || 1 !== preg_match('/\A[a-z0-9][a-z0-9._+-]*@example\.test\z/D', $email) || \strlen($email) > 180) {
            $output->writeln('<error>Use a synthetic email ending in @example.test.</error>');

            return Command::INVALID;
        }
        if (null !== $this->users->findOneBy(['email' => $email])) {
            $output->writeln('Account already exists. No password, roles or data changed.');

            return Command::SUCCESS;
        }
        $password = bin2hex(random_bytes(18));
        $user = (new User())->setEmail($email)->setRoles(true === $input->getOption('admin') ? ['ROLE_ADMIN'] : ['ROLE_USER']);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $output->writeln('Created '.$email.'. Save this generated password privately; it is shown only on creation:');
        $output->writeln($password);

        return Command::SUCCESS;
    }
}
