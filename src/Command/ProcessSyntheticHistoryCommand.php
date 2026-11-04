<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Enum\ProcessingStatus;
use App\Finance\Entity\Source;
use App\Finance\Entity\Statement;
use App\Finance\Entity\Transaction;
use App\Finance\TenantManagers;
use App\Message\ProcessStatementMessage;
use App\MessageHandler\ProcessStatementHandler;
use App\Storage\StatementStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'app:demo:process-history', description: 'One-off paid processing of committed synthetic history in isolated export storage.')]
final class ProcessSyntheticHistoryCommand extends Command
{
    private EntityManagerInterface $em;

    public function __construct(private readonly EntityManagerInterface $central, private readonly TenantManagers $tenants, private readonly StatementStorage $storage, private readonly ProcessStatementHandler $handler, private readonly ValidatorInterface $validator, #[Autowire('%kernel.project_dir%')] private readonly string $root, #[Autowire('%statement_storage_directory%')] private readonly string $storageDirectory)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('export', null, InputOption::VALUE_NONE, 'Export reviewed facts, keeping unknown interpretations as drafts.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->root.'/var/browser/history-final/application.db' !== ($this->central->getConnection()->getParams()['path'] ?? null) || $this->root.'/var/browser/history-final/statements' !== $this->storageDirectory) {
            throw new \RuntimeException('Only the fixed isolated history export database is allowed.');
        }
        $owner = $this->central->getRepository(User::class)->findOneBy(['email' => 'demo@test.com']) ?? throw new \RuntimeException('Load canonical users first.');
        $this->em = $this->tenants->open($owner);
        try {
            $manifest = json_decode((string) file_get_contents($this->root.'/resources/demo-statements/expected.json'), true, flags: \JSON_THROW_ON_ERROR);
            $names = ['demo_current' => 'Maple Harbour Chequing · 7318', 'demo_card' => 'Maple Harbour Rewards Visa · 4826', 'demo_usd' => 'Maple Harbour US Dollar · 9054'];
            $results = [];
            foreach ($manifest['statements'] as $entry) {
                if ('upload_holdout' === $entry['purpose']) {
                    continue;
                }
                $source = $this->em->getRepository(Source::class)->findOneBy(['name' => $names[$entry['source_fixture']]]);
                if (null === $source) {
                    throw new \RuntimeException('Load canonical Alice fixtures into isolated storage first.');
                }
                $filename = 'export-input-'.str_replace('/', '-', $entry['file']);
                $statement = $this->em->getRepository(Statement::class)->findOneBy(['originalFilename' => $filename]);
                if (null === $statement) {
                    $path = $this->root.'/resources/demo-statements/'.$entry['file'];
                    $bytes = (string) file_get_contents($path);
                    $statement = new Statement($source, $this->storage->store(new File($path)), $filename, 'text/csv', \strlen($bytes), hash('sha256', $bytes), statementDate: new \DateTimeImmutable(substr(basename($entry['file']), 0, 7).'-01'), processingStatus: ProcessingStatus::Queued);
                    $this->em->persist($statement);
                    $this->em->flush();
                }
                $id = $statement->getId();
                $ownerId = $owner->getId();
                if (null === $id || null === $ownerId) {
                    throw new \LogicException('Missing persisted identifiers.');
                }
                ($this->handler)(new ProcessStatementMessage($id, $ownerId, $statement->storedFilename));
                $this->em->refresh($statement);
                if (ProcessingStatus::Completed !== $statement->processingStatus) {
                    throw new \RuntimeException('Synthetic processing failed: '.$entry['file'].' '.$statement->processingError);
                }
                $rows = $this->em->getRepository(Transaction::class)->findBy(['statement' => $statement], ['id' => 'ASC']);
                $serialized = [];
                foreach ($rows as $row) {
                    $serialized[] = ['date' => $row->transactionDate?->format('Y-m-d'), 'description' => $row->description, 'amount' => $row->amount, 'currency' => $row->currency, 'direction' => $row->direction?->value, 'category' => $row->category?->name, 'meaning' => $row->meaning->value, 'recurrence' => $row->recurrence->value];
                }
                $results[] = ['file' => $entry['file'], 'source_fixture' => $entry['source_fixture'], 'rows' => $serialized];
                file_put_contents($this->root.'/var/browser/history-final/results.json', json_encode($results, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n");
                $output->writeln($entry['file'].': '.\count($rows).' synthetic drafts processed.');
            }

            if (true === $input->getOption('export')) {
                $this->export($results, $manifest['statements']);
            }

            return Command::SUCCESS;
        } finally {
            $this->tenants->release($this->em);
        }
    }

    /**
     * @param list<array<string, mixed>> $results
     * @param list<array<string, mixed>> $expected
     */
    private function export(array $results, array $expected): void
    {
        $statementDefinitions = Yaml::parseFile($this->root.'/fixtures/statement.yaml')[Statement::class];
        $transactionDefinitions = Yaml::parseFile($this->root.'/fixtures/transaction.yaml')[Transaction::class];
        $statements = [];
        $transactions = [];
        $catalog = Yaml::parseFile($this->root.'/fixtures/category.yaml')[\App\Finance\Entity\Category::class];
        $categoryReferences = [];
        foreach ($catalog as $id => $definition) {
            $categoryReferences[$definition['__construct'][0]] = '@'.$id;
        }
        $known = [];
        foreach ($expected as $entry) {
            $known[$entry['file']] = $entry;
        }
        $review = ['statements' => 0, 'approved' => 0, 'unresolved' => 0, 'meaning_discrepancies' => [], 'category_discrepancies' => [], 'recurrence_discrepancies' => 0, 'corrections' => []];
        foreach ($results as $entry) {
            $expectation = $known[$entry['file']];
            if (\count($entry['rows']) !== \count($expectation['rows'])) {
                throw new \LogicException('Synthetic row count mismatch.');
            }
            $key = str_replace(['/', '-', '.csv'], ['_', '_', ''], $entry['file']);
            $statementId = match ($entry['file']) {
                'chequing/2026-09.csv' => 'demo_statement',
                'credit/2026-09.csv' => 'demo_card_statement',
                default => 'history_'.$key,
            };
            $month = substr(basename($entry['file']), 0, 7);
            $filename = 'history-'.str_replace('/', '-', $entry['file']);
            $bytes = (string) file_get_contents($this->root.'/resources/demo-statements/'.$entry['file']);
            $persistedStatement = $this->em->getRepository(Statement::class)->findOneBy(['originalFilename' => 'export-input-'.str_replace('/', '-', $entry['file'])]);
            $persistedRows = $this->em->getRepository(Transaction::class)->findBy(['statement' => $persistedStatement], ['id' => 'ASC']);
            $statements[$statementId] = ['__construct' => ['@'.$entry['source_fixture'], 'synthetic-'.$key, $filename, 'text/csv', \strlen($bytes), hash('sha256', $bytes)], 'statementDate' => '<(new \\DateTimeImmutable("'.$month.'-01"))>', 'processingStatus' => '<(App\\Enum\\ProcessingStatus::Completed)>', 'uploadedAt' => '<(new \\DateTimeImmutable("2026-11-02T00:00:00+00:00"))>', 'processedAt' => '<(new \\DateTimeImmutable("2026-11-02T00:00:00+00:00"))>'];
            foreach ($entry['rows'] as $index => $row) {
                $truth = $expectation['rows'][$index];
                foreach (['date', 'description', 'amount'] as $fact) {
                    if ($row[$fact] !== $truth[$fact]) {
                        throw new \LogicException('Synthetic factual mismatch: '.$entry['file'].' '.$fact);
                    }
                }
                if ($row['currency'] !== $expectation['currency'] || strtolower($row['direction']) !== $truth['direction']) {
                    throw new \LogicException('Synthetic currency/direction mismatch.');
                }
                $approved = 'unknown' !== $row['meaning'] && null !== $row['category'] && $row['meaning'] === $truth['meaning'];
                if ($approved && 0 !== \count($this->validator->validate($persistedRows[$index], groups: ['approved']))) {
                    throw new \LogicException('Historical record fails approved validation.');
                }
                $persistedRows[$index]->isDraft = !$approved;
                if ($row['meaning'] !== $truth['meaning']) {
                    $review['meaning_discrepancies'][] = ['file' => $entry['file'], 'row' => $index + 1, 'actual' => $row['meaning'], 'expected' => $truth['meaning']];
                }
                if (($categoryReferences[$row['category']] ?? null) !== '@'.$truth['category']) {
                    $review['category_discrepancies'][] = ['file' => $entry['file'], 'row' => $index + 1, 'actual' => $row['category'], 'expected' => $truth['category']];
                }
                if ($row['recurrence'] !== $truth['recurrence']) {
                    ++$review['recurrence_discrepancies'];
                }
                $reference = match ([$entry['file'], $index]) {
                    ['chequing/2026-09.csv', 0] => 'demo_salary',
                    ['chequing/2026-09.csv', 1] => 'demo_rent',
                    ['credit/2026-09.csv', 0] => 'demo_groceries',
                    ['credit/2026-09.csv', 2] => 'demo_subscription',
                    ['usd/2026-09.csv', 3] => 'demo_draft',
                    default => 'history_'.$key.'_'.($index + 1),
                };
                $transactions[$reference] = ['__construct' => ['@'.$statementId, '@'.$entry['source_fixture']], 'transactionDate' => '<(new \\DateTimeImmutable("'.$row['date'].'"))>', 'amount' => $row['amount'], 'currency' => $row['currency'], 'direction' => '<(App\\Enum\\Direction::'.$row['direction'].')>', 'description' => $row['description'], 'category' => null === $row['category'] ? null : $categoryReferences[$row['category']], 'isDraft' => !$approved, 'meaning' => '<(App\\Enum\\TransactionMeaning::'.\App\Enum\TransactionMeaning::from($row['meaning'])->name.')>', 'recurrence' => '<(App\\Enum\\RecurrenceKind::'.\App\Enum\RecurrenceKind::from($row['recurrence'])->name.')>'];
                ++$review[$approved ? 'approved' : 'unresolved'];
            }
            ++$review['statements'];
        }
        $this->em->flush();
        file_put_contents($this->root.'/fixtures/statement.yaml', Yaml::dump([Statement::class => $statements], 4));
        file_put_contents($this->root.'/fixtures/transaction.yaml', Yaml::dump([Transaction::class => $transactions], 4));
        file_put_contents($this->root.'/resources/demo-statements/history-review.json', json_encode($review, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n");
    }
}
