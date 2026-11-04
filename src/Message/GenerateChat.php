<?php

declare(strict_types=1);

namespace App\Message;

final readonly class GenerateChat
{
    public const string TRANSPORT = 'chat';

    public function __construct(public string $conversationId, public int $ownerId, public string $accountIdentity, public string $generationId)
    {
    }
}
