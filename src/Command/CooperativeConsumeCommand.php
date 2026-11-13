<?php

declare(strict_types=1);

namespace App\Command;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use App\Messenger\AmpMessageExecutionStrategy;
use Ineersa\SqliteQueue\Messenger\Transport;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Symfony\Bridge\Doctrine\Messenger\DoctrineClearEntityManagerWorkerSubscriber;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SignalRegistry\SignalRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\ScopedEventDispatcher;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMemoryLimitListener;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\Service\ResetInterface;

use function Amp\delay;

#[AsCommand(name: 'app:messenger:consume', description: 'Consume messages cooperatively in one Amp/Revolt process (four in flight).')]
final class CooperativeConsumeCommand extends Command implements SignalableCommandInterface
{
    private const int IDLE_WAIT_MILLISECONDS = 1_000;
    private const int ACTIVE_WAIT_MILLISECONDS = 100;
    private const int KEEPALIVE_SECONDS = 5;

    private ?Worker $worker = null;
    private ?DeferredCancellation $stop = null;

    public function __construct(
        #[Autowire(service: 'messenger.routable_message_bus')] private readonly RoutableMessageBus $bus,
        #[Autowire(service: 'messenger.receiver_locator')] private readonly ContainerInterface $receivers,
        #[Autowire(service: 'event_dispatcher')] private readonly EventDispatcherInterface $events,
        #[Autowire(service: ServicesResetterInterface::class)] private readonly ResetInterface $resetter,
        #[Autowire(service: 'doctrine.orm.messenger.event_subscriber.doctrine_clear_entity_manager')] private readonly DoctrineClearEntityManagerWorkerSubscriber $doctrineClearer,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        // Lease renewal is a Revolt timer, not SIGALRM: it must run during Amp I/O waits.
        return SignalRegistry::isSupported() ? [\SIGTERM, \SIGINT, \SIGQUIT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->worker?->stop();
        $this->stop?->cancel();

        return false;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('receivers', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Transport names in priority order')
            ->addOption('keepalive', null, InputOption::VALUE_REQUIRED, 'Lease renewal interval in seconds', self::KEEPALIVE_SECONDS)
            ->addOption('memory-limit', 'm', InputOption::VALUE_REQUIRED, 'Worker memory limit', '256M')
            ->addOption('time-limit', 't', InputOption::VALUE_REQUIRED, 'Seconds before stopping consumption and draining', 3600);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $names */
        $names = $input->getArgument('receivers');
        $receivers = [];
        $queues = [];
        $waitOwner = null;
        foreach ($names as $name) {
            if (!$this->receivers->has($name)) {
                throw new \InvalidArgumentException(\sprintf('Unknown transport "%s".', $name));
            }
            $receiver = $this->receivers->get($name);
            if (!$receiver instanceof ReceiverInterface) {
                throw new \LogicException('A Messenger receiver is required.');
            }
            $receivers[$name] = $receiver;
            if ($receiver instanceof Transport) {
                if (null !== $waitOwner && $waitOwner->brokerEndpoint() !== $receiver->brokerEndpoint()) {
                    throw new \LogicException('Cooperative queue receivers must share one broker.');
                }
                $waitOwner ??= $receiver;
                $queues[] = $receiver->queueName();
            }
        }
        $queues = array_values(array_unique($queues));
        $keepalive = (int) $input->getOption('keepalive');
        $timeLimit = (int) $input->getOption('time-limit');
        $memoryLimit = ini_parse_quantity((string) $input->getOption('memory-limit'));
        if ($keepalive < 1 || $timeLimit < 1 || $memoryLimit < 1) {
            throw new \InvalidArgumentException('Keepalive, time and memory limits must be positive.');
        }
        $stop = $this->stop = new DeferredCancellation();
        $onDrained = function (): void {
            // Keep Doctrine's native all-manager clearing, but never detach a suspended peer's entities.
            $this->doctrineClearer->onWorkerMessageHandled();
            $this->resetter->reset();
        };
        $strategy = new AmpMessageExecutionStrategy($this->bus, $onDrained);
        $events = new ScopedEventDispatcher($this->events);
        // The native consume command installs its reset listener locally, not globally.
        // Doctrine also has a global handled/failed subscriber, detached only while this command runs below.
        $events->addSubscriber(new StopWorkerOnMemoryLimitListener($memoryLimit, $this->logger));
        $events->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use ($waitOwner, $queues, $strategy, $stop): void {
            if (!$event->isWorkerIdle() || $stop->isCancelled()) {
                return;
            }
            $milliseconds = $strategy->hasPending() ? self::ACTIVE_WAIT_MILLISECONDS : self::IDLE_WAIT_MILLISECONDS;
            if (null === $waitOwner) {
                delay($milliseconds / 1000);

                return;
            }
            try {
                // Notification connection is distinct from operation/ACK connections.
                // Scheduler is re-polled within this bounded budget, not waited on as a broker queue.
                $waitOwner->waitAny($queues, $milliseconds, $stop->getCancellation());
            } catch (TransportException $error) {
                if (!self::isStopCancellation($stop, $error)) {
                    throw $error;
                }
            }
        }, -10);
        $this->worker = new Worker($receivers, $this->bus, $events, $this->logger, messageExecutionStrategy: $strategy);
        $leaseTimer = EventLoop::repeat((float) $keepalive, function () use ($keepalive): void {
            $this->worker?->keepalive($keepalive);
        });
        $deadlineTimer = EventLoop::delay((float) $timeLimit, function (): void {
            $this->worker?->stop();
            $this->stop?->cancel();
        });
        EventLoop::unreference($leaseTimer);
        EventLoop::unreference($deadlineTimer);
        $output->writeln(\sprintf('Consuming %s in one process with at most %d cooperative handlers.', implode(', ', $names), AmpMessageExecutionStrategy::MAX_IN_FLIGHT));
        // ScopedEventDispatcher forwards these events: remove only the native clear subscriber,
        // retaining every other handled/failed listener and restoring ordinary consumer behavior on exit.
        $this->events->removeSubscriber($this->doctrineClearer);
        try {
            // Idle readiness is Amp-aware above; never enter Worker's blocking Clock sleep.
            $this->worker->run(['sleep' => 0, 'fetch_size' => 1, 'time_limit' => $timeLimit]);
        } finally {
            try {
                $strategy->shutdown();
                $onDrained();
            } finally {
                $this->events->addSubscriber($this->doctrineClearer);
                EventLoop::cancel($leaseTimer);
                EventLoop::cancel($deadlineTimer);
                $this->worker = null;
                $this->stop = null;
            }
        }

        return Command::SUCCESS;
    }

    private static function isStopCancellation(DeferredCancellation $stop, \Throwable $error): bool
    {
        if (!$stop->isCancelled()) {
            return false;
        }
        for ($current = $error; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof CancelledException) {
                return true;
            }
        }

        return false;
    }
}
