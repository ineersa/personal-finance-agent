<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\User;
use App\Enum\ProcessingStatus;
use App\Finance\Entity\Category;
use App\Finance\Entity\Transaction;
use App\Finance\TenantManagers;
use App\Import\CsvParser;
use App\Import\ImportException;
use App\Import\ImportUpdates;
use App\Import\TransactionInterpreter;
use App\Message\ProcessStatementMessage;
use App\Repository\CategoryRepository;
use App\Repository\StatementRepository;
use App\Storage\StatementStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessStatementHandler
{
    public function __construct(private EntityManagerInterface $entityManager, private TenantManagers $tenants, private StatementStorage $storage, private CsvParser $parser, private TransactionInterpreter $interpreter, private ImportUpdates $updates)
    {
    }

    public function __invoke(ProcessStatementMessage $message): void
    {
        if (!isset($message->storedFilename)) {
            return;
        }
        $owner = $this->entityManager->find(User::class, $message->ownerId);
        if (null === $owner) {
            return;
        }
        if (\in_array('ROLE_ADMIN', $owner->getRoles(), true)) {
            return;
        }
        $em = $this->tenants->open($owner);
        try {
            $statements = new StatementRepository($em);
            $categories = new CategoryRepository($em);
            $statement = $statements->find($message->statementId);
            if (null === $statement || $statement->storedFilename !== $message->storedFilename) {
                return;
            }
            // A worker can die after saving Running. Redelivery must be able to resume it.
            if (!$statements->transition($message->statementId, $statement->storedFilename, ProcessingStatus::Queued, ProcessingStatus::Running, withoutTransactions: true)
                && !$statements->transition($message->statementId, $statement->storedFilename, ProcessingStatus::Running, ProcessingStatus::Running, withoutTransactions: true)) {
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
                $categories = $categories->findAllIndexedByName();
                $catalog = array_map(static fn (Category $category): string => $category->description ?? $category->name, $categories);
                $rows = $this->interpreter->interpret($rows, $statement->source->instrumentKind, $catalog);
                foreach ($rows as $row) {
                    $category = null === $row->categoryName ? null : $categories[$row->categoryName];
                    $em->persist(new Transaction(
                        statement: $statement,
                        source: $statement->source,
                        transactionDate: $row->date,
                        amount: $row->amount,
                        currency: $row->currency,
                        direction: $row->direction,
                        description: $row->description,
                        category: $category,
                        isDraft: true,
                        meaning: $row->meaning,
                        recurrence: $row->recurrence,
                    ));
                }
                $statement->processingStatus = ProcessingStatus::Completed;
                $statement->processingError = null;
                $statement->processedAt = new \DateTimeImmutable();
                $em->flush();
            } catch (\Throwable $error) {
                $safeError = $error instanceof ImportException ? $error->getMessage() : 'Processing failed. No draft results were accepted. Retry after checking storage, source configuration and provider availability.';
                if (!$statements->transition($message->statementId, $statement->storedFilename, ProcessingStatus::Running, ProcessingStatus::Failed, $safeError)) {
                    return;
                }
                $statement->processingStatus = ProcessingStatus::Failed;
                $statement->processingError = $safeError;
                // Do not log exception chains: provider/storage exceptions can expose personal data.
            }
            if (ProcessingStatus::Completed === $statement->processingStatus) {
                try {
                    $drafts = $em->getRepository(Transaction::class)->findBy(['statement' => $statement, 'isDraft' => true], ['id' => 'ASC']);
                } catch (\Throwable) {
                    return;
                }
            }
            $this->updates->publish($owner, $statement, $drafts);
        } finally {
            $this->tenants->release($em);
        }
    }
}
