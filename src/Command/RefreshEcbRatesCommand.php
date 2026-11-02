<?php

declare(strict_types=1);

namespace App\Command;

use App\Finance\EcbRateRefresher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

#[AsCommand(name: 'app:currency:refresh', description: 'Refresh ECB daily rates and reconcile missing recent dates when needed.')]
#[AsPeriodicTask('1 hour', from: '2020-01-01 00:00:00 UTC')]
final class RefreshEcbRatesCommand extends Command
{
    public function __construct(private readonly EcbRateRefresher $refresher)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->refresher->refresh();
        $output->writeln(sprintf('Updated %d calendar-day currency rates.', $count));

        return Command::SUCCESS;
    }
}
