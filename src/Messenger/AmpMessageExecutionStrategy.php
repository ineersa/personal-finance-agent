<?php

declare(strict_types=1);

namespace App\Messenger;

use Amp\CancelledException;
use Amp\Future;
use Amp\TimeoutCancellation;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Execution\MessageExecutionCallback;
use Symfony\Component\Messenger\Execution\MessageExecutionStrategyInterface;
use Symfony\Component\Messenger\Execution\SyncMessageExecutionStrategy;
use Symfony\Component\Messenger\MessageBusInterface;

use function Amp\async;
use function Amp\Future\awaitFirst;

/** Amp owns suspension; Symfony retains dispatch, acknowledgement and delivery identity. */
final class AmpMessageExecutionStrategy implements MessageExecutionStrategyInterface
{
    public const int MAX_IN_FLIGHT = 10;
    private const float POLL_SECONDS = 0.1;

    /** @var array<int, Future<\Closure(): void>> */
    private array $pending = [];
    private int $nextId = 0;

    public function __construct(private readonly MessageBusInterface $bus, private readonly \Closure $onDrained)
    {
    }

    public function execute(Envelope $envelope, string $transportName, callable $onHandled): void
    {
        if (!$onHandled instanceof MessageExecutionCallback) {
            throw new \LogicException('The cooperative strategy requires the native Worker delivery callback.');
        }
        $this->pending[++$this->nextId] = async(function () use ($envelope, $transportName, $onHandled): \Closure {
            $completion = null;
            $callback = new MessageExecutionCallback(
                static function (Envelope $handled, string $transport, bool &$acked, ?\Throwable $error = null) use (&$completion, $onHandled): void {
                    // Keep the native ack variable alive: batch acknowledgement may change it later.
                    $completion = static function () use ($handled, $transport, &$acked, $error, $onHandled): void {
                        $onHandled($handled, $transport, $acked, $error);
                    };
                },
                $onHandled->acknowledge(...),
            );
            // A separate native strategy per dispatch avoids sharing its mutable held-ack state.
            (new SyncMessageExecutionStrategy($this->bus, $callback->acknowledge(...)))->execute($envelope, $transportName, $callback);
            if (null === $completion) {
                throw new \LogicException('Native dispatch did not supply its completion callback.');
            }

            return $completion;
        });
    }

    public function shouldPauseConsumption(): bool
    {
        return \count($this->pending) >= self::MAX_IN_FLIGHT;
    }

    public function hasPending(): bool
    {
        return [] !== $this->pending;
    }

    /** @phpstan-impure */
    public function wait(callable $onHandled, ?float $timeout = null): bool
    {
        if ([] === $this->pending) {
            return false;
        }
        $deadline = null === $timeout ? null : hrtime(true) / 1e9 + $timeout;
        do {
            if ($this->drainReady()) {
                return true;
            }
            $remaining = null === $deadline ? self::POLL_SECONDS : min(self::POLL_SECONDS, $deadline - hrtime(true) / 1e9);
            if ($remaining <= 0) {
                return false;
            }
            try {
                awaitFirst($this->pending, new TimeoutCancellation($remaining));
            } catch (CancelledException) {
                // A bounded scheduler tick, not handler cancellation. Errors from futures propagate.
            }
        } while (null === $deadline || hrtime(true) / 1e9 < $deadline);

        return $this->drainReady();
    }

    public function flush(callable $onHandled, bool|float $force = false): bool
    {
        $handled = $this->drainReady();
        if (true === $force) {
            while ($this->hasPending()) {
                $handled = $this->wait($onHandled) || $handled;
            }
        }

        return $handled;
    }

    public function shutdown(): void
    {
        // Graceful shutdown drains, never abandons suspended handlers or their receipts.
        $this->flush(static function (): void {}, true);
    }

    private function drainReady(): bool
    {
        $handled = false;
        foreach ($this->pending as $id => $future) {
            if (!$future->isComplete()) {
                continue;
            }
            $completion = $future->await();
            // Only Worker control flow settles receipts; it cannot admit another message here.
            // Remove first so an error during native settlement cannot replay this callback at shutdown.
            unset($this->pending[$id]);
            $completion();
            $handled = true;
        }
        if ($handled && [] === $this->pending) {
            ($this->onDrained)();
        }

        return $handled;
    }
}
