<?php

declare(strict_types=1);

namespace App\Message;

use App\Chat\ChatModel;
use App\Chat\ReasoningEffort;
use App\Import\DocumentExtraction;

final readonly class ProcessStatementMessage
{
    public function __construct(public int $statementId, public int $ownerId, public ?string $storedFilename = null, public ChatModel $extractionModel = DocumentExtraction::MODEL, public ReasoningEffort $extractionEffort = DocumentExtraction::EFFORT)
    {
    }
}
