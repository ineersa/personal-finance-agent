<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ProcessStatementMessage
{
    public function __construct(public int $statementId, public int $ownerId, public ?string $storedFilename = null)
    {
    }
}
