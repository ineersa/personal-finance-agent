<?php

declare(strict_types=1);

namespace App\Import;

use App\Message\ProcessStatement;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[AsEventListener(event: WorkerMessageFailedEvent::class, priority: -256)]
final readonly class ImportFailureListener
{
    public function __construct(private Connection $connection, private LoggerInterface $logger)
    {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof ProcessStatement || 'statement_import' !== $event->getReceiverName() || $event->willRetry()) {
            return;
        }
        try {
            // Covers failures before handler construction, without exposing exception payloads.
            $this->connection->executeStatement("UPDATE statement SET processing_status = 'failed', processing_error = ? WHERE id = ? AND user_id = ? AND processing_status IN ('queued', 'running') AND source_id IN (SELECT id FROM source WHERE user_id = ?)", ['The worker could not handle this statement. Retry processing after checking worker availability.', $message->statementId, $message->ownerId, $message->ownerId]);
        } catch (\Throwable) {
            $this->logger->warning('Statement failure status could not be saved.', ['statement_id' => $message->statementId]);
        }
    }
}
