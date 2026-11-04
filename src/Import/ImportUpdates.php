<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\User;
use App\Finance\Entity\Statement;
use App\Finance\Entity\Transaction;
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
    public function publish(User $owner, Statement $statement, array $drafts): void
    {
        if (!$this->enabled || \in_array('ROLE_ADMIN', $owner->getRoles(), true)) {
            return;
        }
        // Status is already durable. A notification failure must never fail or repeat the import.
        try {
            $this->hub->publish(new Update($this->topic->forUser($owner), $this->twig->render('statement/update.stream.html.twig', ['statement' => $statement, 'drafts' => $drafts]), true));
        } catch (\Throwable) {
            $this->logger->warning('Statement live update unavailable.', ['statement_id' => $statement->getId()]);
        }
    }
}
