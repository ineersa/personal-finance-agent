<?php

declare(strict_types=1);

namespace App\Command;

use App\Finance\EcbRateImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:currency:import', description: 'Import three years of ECB rates, filling calendar gaps with preceding rates.')]
final class ImportEcbRatesCommand extends Command
{
    public function __construct(private readonly EcbRateImporter $importer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->importer->import();
        $output->writeln(sprintf('Stored %d calendar-day currency rates. Older stored history was preserved.', $count));

        return Command::SUCCESS;
    }
}
