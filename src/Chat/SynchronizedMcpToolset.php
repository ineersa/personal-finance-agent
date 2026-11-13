<?php

declare(strict_types=1);

namespace App\Chat;

use Amp\Sync\LocalMutex;
use Mcp\Schema\Result\CallToolResult;
use Symfony\AI\Agent\Bridge\Mcp\ClientToolset;
use Symfony\AI\Agent\Bridge\Mcp\ToolsetInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** The native HTTP transport has one active request; serialize only this client. */
#[Exclude]
final readonly class SynchronizedMcpToolset implements ToolsetInterface
{
    private LocalMutex $mutex;

    public function __construct(private ClientToolset $connection)
    {
        $this->mutex = new LocalMutex();
    }

    public function getName(): string
    {
        return $this->connection->getName();
    }

    public function getTools(): array
    {
        $lock = $this->mutex->acquire();
        try {
            // Native lazy connection, initialization and paginated discovery share the same lock as calls.
            return $this->connection->getTools();
        } finally {
            $lock->release();
        }
    }

    public function callTool(string $name, array $arguments = []): CallToolResult
    {
        $lock = $this->mutex->acquire();
        try {
            return $this->connection->callTool($name, $arguments);
        } finally {
            $lock->release();
        }
    }
}
