<?php

declare(strict_types=1);

namespace App\Message;

final readonly class GenerateChat
{
    public const string TRANSPORT = 'chat';

    public function __construct(public string $conversationId, public ?string $turnId = null, public ?string $accountIdentity = null)
    {
    }
}
