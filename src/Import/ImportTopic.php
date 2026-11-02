<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\User;

/** Stable Mercure identity, not an HTTP endpoint or the current installation's origin. */
final class ImportTopic
{
    public function forUser(User $user): string
    {
        return 'urn:personal-finance:user:'.$user->getId().':imports';
    }
}
