<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Statement;
use App\Entity\Transaction;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

final readonly class ImportUpdates
{
    public function __construct(private HubInterface $hub, private Environment $twig, private LoggerInterface $logger, private ImportTopic $topic, #[Autowire('%env(bool:IMPORT_UPDATES_ENABLED)%')] private bool $enabled)
    {
    }

    /** @param list<Transaction> $drafts */
    public function publish(Statement $statement, array $drafts): void
    {
        if (!$this->enabled || $statement->source->getUser()->getId() !== $statement->getUser()->getId() || \in_array('ROLE_ADMIN', $statement->getUser()->getRoles(), true)) {
            return;
        }
        // Status is already durable. A notification failure must never fail or repeat the import.
        try {
            $this->hub->publish(new Update($this->topic->forUser($statement->getUser()), $this->twig->render('statement/update.stream.html.twig', ['statement' => $statement, 'drafts' => $drafts]), true));
        } catch (\Throwable) {
            $this->logger->warning('Statement live update unavailable.', ['statement_id' => $statement->getId()]);
        }
    }
}
