<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\AI\Platform\Message\MessageBag;

final readonly class GenerateChat
{
    public const string TRANSPORT = 'chat';

    public function __construct(public string $conversationId, public int $ownerId, public MessageBag $input)
    {
    }
}
