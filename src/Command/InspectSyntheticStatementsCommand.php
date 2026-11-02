<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Category;
use App\Enum\InstrumentKind;
use App\Import\CsvParser;
use App\Import\ImportException;
use App\Import\TransactionInterpreter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'app:demo:inspect-statements', description: 'Interpret only the three committed synthetic October CSVs through hosted Jev, without database writes.')]
final class InspectSyntheticStatementsCommand extends Command
{
    public function __construct(
        private readonly CsvParser $parser,
        private readonly TransactionInterpreter $interpreter,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDirectory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->writeReport(['status' => 'running']);
        $definitions = Yaml::parseFile($this->projectDirectory.'/fixtures/category.yaml');
        if (!\is_array($definitions) || !isset($definitions[Category::class]) || !\is_array($definitions[Category::class])) {
            throw new \LogicException('Canonical categories are missing.');
        }
        $categories = [];
        foreach ($definitions[Category::class] as $definition) {
            if (!\is_array($definition) || !isset($definition['__construct']) || !\is_array($definition['__construct']) || !isset($definition['__construct'][0], $definition['__construct'][1]) || !\is_string($definition['__construct'][0]) || !\is_string($definition['__construct'][1])) {
                throw new \LogicException('Canonical category definition is invalid.');
            }
            $categories[$definition['__construct'][0]] = $definition['__construct'][1];
        }
        $results = [];
        try {
            foreach (['chequing', 'credit', 'usd'] as $source) {
                $content = file_get_contents($this->projectDirectory.'/resources/demo-statements/'.$source.'/2026-10.csv');
                if (false === $content) {
                    throw new \LogicException('Committed synthetic input is missing.');
                }
                $instrument = 'credit' === $source ? InstrumentKind::CreditCard : InstrumentKind::BankAccount;
                $transactions = $this->parser->parse($content, 'usd' === $source ? 'USD' : 'CAD', $instrument);
                $interpreted = $this->interpreter->interpret($transactions, $instrument, $categories);
                foreach ($interpreted as $row) {
                    $results[$source][] = ['row' => $row->rowNumber, 'date' => $row->date->format('Y-m-d'), 'amount' => $row->amount, 'currency' => $row->currency, 'direction' => $row->direction->value, 'category' => $row->categoryName, 'meaning' => $row->meaning->value, 'recurrence' => $row->recurrence->value];
                }
                $output->writeln($source.': '.\count($interpreted).' synthetic rows interpreted.');
            }
        } catch (ImportException $error) {
            $this->writeReport(['status' => 'failed', 'error' => $error->getMessage()]);
            $output->writeln($error->getMessage());

            return Command::FAILURE;
        }
        $this->writeReport(['status' => 'synthetic provider diagnostic only; no records persisted or approved', 'rows' => $results]);
        $output->writeln('Synthetic interpretation: var/demo-interpretation/result.json. No database writes or historical finalization.');

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $result */
    private function writeReport(array $result): void
    {
        $directory = $this->projectDirectory.'/var/demo-interpretation';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        if (false === file_put_contents($directory.'/result.json', json_encode($result, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n")) {
            throw new \RuntimeException('Cannot write synthetic interpretation report.');
        }
    }
}
