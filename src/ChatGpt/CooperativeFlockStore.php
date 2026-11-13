<?php

declare(strict_types=1);

namespace App\ChatGpt;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\Store\FlockStore;

use function Amp\delay;

/** Native exclusive auth-file locks, with Amp-owned contention waits. */
#[Exclude]
final class CooperativeFlockStore extends FlockStore
{
    private const float RETRY_SECONDS = 0.02;

    public function waitAndSave(Key $key): void
    {
        while (true) {
            try {
                parent::save($key);

                return;
            } catch (LockConflictedException) {
                delay(self::RETRY_SECONDS);
            }
        }
    }
}
