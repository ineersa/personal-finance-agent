<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Message\ProcessStatement;
use App\Repository\StatementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:statement:dispatch', description: 'Republish an owned statement identifier; completed or running imports remain unchanged.')]
final readonly class DispatchStatementCommand
{
    public function __construct(private EntityManagerInterface $entityManager, private StatementRepository $statements, private MessageBusInterface $bus)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Argument] int $statementId, #[Argument] string $ownerEmail): int
    {
        $owner = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $ownerEmail]);
        if (null === $owner || \in_array('ROLE_ADMIN', $owner->getRoles(), true)) {
            $io->error('Owner is unavailable.');

            return Command::FAILURE;
        }
        $statement = $this->statements->findForUser($owner, $statementId);
        if (null === $statement || $statement->source->getUser()->getId() !== $owner->getId()) {
            $io->error('Owned statement is unavailable.');

            return Command::FAILURE;
        }
        $this->bus->dispatch(new ProcessStatement($statementId, $owner->getId() ?? throw new \LogicException('Owner is not persisted.'), $statement->storedFilename));
        $io->success('Statement identifier dispatched.');

        return Command::SUCCESS;
    }
}
