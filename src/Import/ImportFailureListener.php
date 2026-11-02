<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Statement;
use App\Enum\ProcessingStatus;
use App\Message\ProcessStatementMessage;
use App\Repository\StatementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[AsEventListener(event: WorkerMessageFailedEvent::class, priority: -256)]
final readonly class ImportFailureListener
{
    public function __construct(private StatementRepository $statements, private LoggerInterface $logger, private EntityManagerInterface $entityManager, private ImportUpdates $updates)
    {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof ProcessStatementMessage || !isset($message->storedFilename) || 'statement_import' !== $event->getReceiverName() || $event->willRetry()) {
            return;
        }
        try {
            // Covers failures before handler construction, without exposing exception payloads.
            foreach ([ProcessingStatus::Queued, ProcessingStatus::Running] as $status) {
                if ($this->statements->transition($message->statementId, $message->ownerId, $message->storedFilename, $status, ProcessingStatus::Failed, 'The worker could not handle this statement. Retry processing after checking worker availability.')) {
                    $statement = $this->entityManager->find(Statement::class, $message->statementId);
                    if (null === $statement) {
                        return;
                    }
                    $this->entityManager->refresh($statement);
                    $this->entityManager->refresh($statement->getUser());
                    $this->entityManager->refresh($statement->source);
                    if (ProcessingStatus::Failed === $statement->processingStatus && $statement->storedFilename === $message->storedFilename && $statement->getUser()->getId() === $message->ownerId) {
                        $this->updates->publish($statement, []);
                    }

                    return;
                }
            }
        } catch (\Throwable) {
            $this->logger->warning('Statement failure update could not be completed.', ['statement_id' => $message->statementId]);
        }
    }
}
