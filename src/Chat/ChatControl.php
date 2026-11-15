<?php

declare(strict_types=1);

namespace App\Chat;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\ExecutionStatus;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Shared cancellation mailbox; native executions remain local to their owning consumer. */
final class ChatControl
{
    public const string STOP_ROUTE = 'app_chat_stop';
    public const string STATUS_ROUTE = 'app_chat_status';
    public const string STOP_CSRF = 'chat-stop';
    private const string ACTIVE_PREFIX = 'active.';
    private const string CANCEL_PREFIX = 'cancel.';
    private const int PENDING_SECONDS = 60;
    private const int LIVE_SECONDS = 30;
    private const int TERMINAL_SECONDS = 300;
    private const float POLL_SECONDS = 0.25;
    private const float HEARTBEAT_SECONDS = 2;

    /** @var array<string, array{conversation: string, execution: ExecutionInterface}> */
    private array $executions = [];
    private ?string $timer = null;
    private float $heartbeat = 0;

    public function __construct(#[Autowire(service: 'cache.chat_control')] private readonly CacheItemPoolInterface $cache, private readonly LoggerInterface $logger)
    {
    }

    /** @return array{turn: string, status: string, stopping: bool}|null */
    public function state(string $conversation): ?array
    {
        $item = $this->cache->getItem(self::ACTIVE_PREFIX.$conversation);
        if (!$item->isHit()) {
            return null;
        }
        /** @var array{turn: string, status: string, stopping: bool} $state */
        $state = $item->get();

        return [...$state, 'stopping' => $state['stopping'] || $this->cache->hasItem(self::CANCEL_PREFIX.$state['turn'])];
    }

    public function pending(string $conversation, string $turn): void
    {
        $state = $this->state($conversation);
        if (null !== $state && !ExecutionStatus::from($state['status'])->isFinished()) {
            throw new \DomainException('Wait for the current response or stop it before sending another message.');
        }
        $this->write($conversation, $turn, ExecutionStatus::Pending, self::PENDING_SECONDS);
    }

    public function requestStop(string $conversation, string $turn): void
    {
        $state = $this->state($conversation);
        if (null === $state || $state['turn'] !== $turn || ExecutionStatus::from($state['status'])->isFinished()) {
            return;
        }
        $item = $this->cache->getItem(self::CANCEL_PREFIX.$turn);
        $item->set(true)->expiresAfter(self::TERMINAL_SECONDS);
        if (!$this->cache->save($item)) {
            throw new \RuntimeException('Stop is temporarily unavailable.');
        }
    }

    public function current(string $conversation, string $turn): bool
    {
        $state = $this->state($conversation);

        return null !== $state && $state['turn'] === $turn && !ExecutionStatus::from($state['status'])->isFinished();
    }

    public function register(string $conversation, string $turn, ExecutionInterface $execution): void
    {
        $this->executions[$turn] = ['conversation' => $conversation, 'execution' => $execution];
        $this->write($conversation, $turn, ExecutionStatus::Running, self::LIVE_SECONDS);
        $this->tick();
        if (null === $this->timer) {
            $this->timer = EventLoop::repeat(self::POLL_SECONDS, $this->tick(...));
            EventLoop::unreference($this->timer);
        }
    }

    public function finish(string $conversation, string $turn, ExecutionStatus $status): void
    {
        unset($this->executions[$turn]);
        if ([] === $this->executions && null !== $this->timer) {
            EventLoop::cancel($this->timer);
            $this->timer = null;
        }
        $this->cache->deleteItem(self::CANCEL_PREFIX.$turn);
        if (($this->state($conversation)['turn'] ?? null) === $turn) {
            $this->write($conversation, $turn, $status, self::TERMINAL_SECONDS);
        }
    }

    private function tick(): void
    {
        try {
            $this->poll();
        } catch (\Throwable) {
            // A callback failure must not abort unrelated executions in the cooperative worker.
            $this->logger->warning('Chat control poll unavailable.');
        }
    }

    private function poll(): void
    {
        $keys = array_map(static fn (string $turn): string => self::CANCEL_PREFIX.$turn, array_keys($this->executions));
        foreach ($this->cache->getItems($keys) as $key => $item) {
            if ($item->isHit()) {
                $turn = substr($key, \strlen(self::CANCEL_PREFIX));
                $this->executions[$turn]['execution']->cancel();
                $this->cache->deleteItem($key);
            }
        }
        if (microtime(true) - $this->heartbeat < self::HEARTBEAT_SECONDS) {
            return;
        }
        $this->heartbeat = microtime(true);
        foreach ($this->executions as $turn => $run) {
            if (!$this->current($run['conversation'], $turn)) {
                $run['execution']->cancel();
                continue;
            }
            // cancel() marks the native object immediately; keep Send blocked until its handler unwinds.
            $this->write($run['conversation'], $turn, ExecutionStatus::Running, self::LIVE_SECONDS, ExecutionStatus::Cancelled === $run['execution']->getStatus());
        }
    }

    private function write(string $conversation, string $turn, ExecutionStatus $status, int $seconds, bool $stopping = false): void
    {
        $item = $this->cache->getItem(self::ACTIVE_PREFIX.$conversation);
        $item->set(['turn' => $turn, 'status' => $status->value, 'stopping' => $stopping])->expiresAfter($seconds);
        if (!$this->cache->save($item)) {
            throw new \RuntimeException('Chat control is temporarily unavailable.');
        }
    }
}
