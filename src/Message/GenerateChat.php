<?php

declare(strict_types=1);

namespace App\Message;

use App\Chat\ChatModel;
use App\Chat\ReasoningEffort;
use Symfony\AI\Platform\Message\MessageBag;

final readonly class GenerateChat
{
    public const string TRANSPORT = 'chat';

    public function __construct(public string $conversationId, public int $ownerId, public MessageBag $input, public ChatModel $model, public ReasoningEffort $effort)
    {
    }
}
