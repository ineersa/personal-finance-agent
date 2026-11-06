<?php

declare(strict_types=1);

namespace App\Chat;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints as Assert;

#[Exclude]
final readonly class ChatSelection
{
    public function __construct(#[Assert\Uuid] public string $conversation = '')
    {
    }
}
