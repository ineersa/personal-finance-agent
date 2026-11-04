<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\User;
use App\Enum\ProcessingStatus;
use App\Finance\Entity\Statement;
use App\Finance\TenantManagers;
use App\Message\ProcessStatementMessage;
use App\Repository\StatementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[AsEventListener(event: WorkerMessageFailedEvent::class, priority: -256)]
final readonly class ImportFailureListener
{
    public function __construct(private TenantManagers $tenants, private LoggerInterface $logger, private EntityManagerInterface $entityManager, private ImportUpdates $updates)
    {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof ProcessStatementMessage || !isset($message->storedFilename) || 'statement_import' !== $event->getReceiverName() || $event->willRetry()) {
            return;
        }
        try {
            $em = null;
            try {
                $owner = $this->entityManager->find(User::class, $message->ownerId);
                if (null === $owner || \in_array('ROLE_ADMIN', $owner->getRoles(), true)) {
                    return;
                }
                $em = $this->tenants->open($owner);
                $statements = new StatementRepository($em);
                // Covers failures before handler construction, without exposing exception payloads.
                $error = 'The worker could not handle this statement. Retry processing after checking worker availability.';
                foreach ([ProcessingStatus::Queued, ProcessingStatus::Running] as $status) {
                    if ($statements->transition($message->statementId, $message->storedFilename, $status, ProcessingStatus::Failed, $error)) {
                        $statement = $em->find(Statement::class, $message->statementId);
                        if (null === $statement) {
                            return;
                        }
                        $statement->processingStatus = ProcessingStatus::Failed;
                        $statement->processingError = $error;
                        $this->updates->publish($owner, $statement, []);

                        return;
                    }
                }
            } finally {
                if (null !== $em) {
                    $this->tenants->release($em);
                }
            }
        } catch (\Throwable) {
            $this->logger->warning('Statement failure update could not be completed.', ['owner_id' => $message->ownerId, 'statement_id' => $message->statementId]);
        }
    }
}
