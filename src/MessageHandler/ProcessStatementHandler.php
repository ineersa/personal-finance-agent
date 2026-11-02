<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Category;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\ProcessingStatus;
use App\Import\CsvParser;
use App\Import\ImportException;
use App\Import\ImportUpdates;
use App\Import\TransactionInterpreter;
use App\Message\ProcessStatement;
use App\Repository\StatementRepository;
use App\Storage\StatementStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessStatementHandler
{
    public function __construct(private EntityManagerInterface $entityManager, private StatementRepository $statements, private StatementStorage $storage, private CsvParser $parser, private TransactionInterpreter $interpreter, private ImportUpdates $updates)
    {
    }

    public function __invoke(ProcessStatement $message): void
    {
        $owner = $this->entityManager->find(User::class, $message->ownerId);
        if (null === $owner || \in_array('ROLE_ADMIN', $owner->getRoles(), true)) {
            return;
        }
        $statement = $this->statements->findForUser($owner, $message->statementId);
        if (null === $statement || $statement->source->getUser()->getId() !== $owner->getId()) {
            return;
        }
        $connection = $this->entityManager->getConnection();
        // Atomic single-winner claim; redeliveries of running/completed/failed statements are no-ops.
        if (1 !== $connection->executeStatement("UPDATE statement SET processing_status = 'running', processing_error = NULL WHERE id = ? AND user_id = ? AND processing_status = 'queued'", [$message->statementId, $message->ownerId])) {
            return;
        }
        $statement->processingStatus = ProcessingStatus::Running;
        $drafts = [];
        try {
            if (null === $statement->source->currency) {
                throw new ImportException('The source currency is missing.');
            }
            $stream = $this->storage->read($statement->storedFilename);
            try {
                $content = stream_get_contents($stream, 10 * 1024 * 1024 + 1);
            } finally {
                fclose($stream);
            }
            if (false === $content || $statement->byteSize !== \strlen($content) || !hash_equals($statement->sha256, hash('sha256', $content))) {
                throw new ImportException('Statement bytes are unavailable or do not match their saved metadata.');
            }
            $rows = $this->parser->parse($content, $statement->source->currency, $statement->source->instrumentKind);
            $categories = $this->entityManager->getRepository(Category::class)->findAll();
            $catalog = [];
            $byName = [];
            foreach ($categories as $category) {
                $catalog[$category->name] = $category->description ?? $category->name;
                $byName[$category->name] = $category;
            }
            $rows = $this->interpreter->interpret($rows, $statement->source->instrumentKind, $catalog);
            // No provider, byte storage or broker operations inside this short write transaction.
            $this->entityManager->wrapInTransaction(function () use ($statement, $owner, $rows, $byName): void {
                $this->entityManager->refresh($owner);
                $this->entityManager->refresh($statement->source);
                if ($statement->source->getUser()->getId() !== $owner->getId() || \in_array('ROLE_ADMIN', $owner->getRoles(), true)) {
                    throw new ImportException('Statement ownership is no longer available.');
                }
                foreach ($rows as $row) {
                    $this->entityManager->persist(new Transaction($owner, $statement, $statement->source, $row->date, $row->amount, $row->currency, $row->direction, $row->description, null === $row->categoryName ? null : $byName[$row->categoryName], true, $row->meaning, $row->recurrence));
                }
                $statement->processingStatus = ProcessingStatus::Completed;
                $statement->processingError = null;
                $statement->processedAt = new \DateTimeImmutable();
            });
        } catch (\Throwable $error) {
            $safeError = $error instanceof ImportException ? $error->getMessage() : 'Processing failed. No draft results were accepted. Retry after checking storage, source configuration and provider availability.';
            $connection->executeStatement("UPDATE statement SET processing_status = 'failed', processing_error = ? WHERE id = ? AND user_id = ?", [$safeError, $message->statementId, $message->ownerId]);
            $statement->processingStatus = ProcessingStatus::Failed;
            $statement->processingError = $safeError;
            // Do not log exception chains: provider/storage exceptions can expose personal data.
        }
        if (ProcessingStatus::Completed === $statement->processingStatus) {
            try {
                $drafts = $this->entityManager->getRepository(Transaction::class)->findBy(['statement' => $statement, 'isDraft' => true], ['id' => 'ASC']);
            } catch (\Throwable) {
                return;
            }
        }
        $this->updates->publish($statement, $drafts);
    }
}
