<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\User;

final class ChatTopic
{
    public function forUser(User $user): string
    {
        return 'urn:personal-finance:user:'.$user->getId().':chat:'.$user->getChatGptIdentity();
    }
}
