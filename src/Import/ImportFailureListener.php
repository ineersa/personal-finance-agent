<?php

declare(strict_types=1);

namespace App\Import;

use App\Enum\ProcessingStatus;
use App\Message\ProcessStatementMessage;
use App\Repository\StatementRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[AsEventListener(event: WorkerMessageFailedEvent::class, priority: -256)]
final readonly class ImportFailureListener
{
    public function __construct(private StatementRepository $statements, private LoggerInterface $logger)
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
                    break;
                }
            }
        } catch (\Throwable) {
            $this->logger->warning('Statement failure status could not be saved.', ['statement_id' => $message->statementId]);
        }
    }
}
