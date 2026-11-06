<?php

declare(strict_types=1);

namespace App\Chat;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints as Assert;

#[Exclude]
final readonly class ChatAction
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $conversation,
        #[Assert\Uuid]
        public string $selected = '',
        public bool $favourite = false,
    ) {
    }
}
