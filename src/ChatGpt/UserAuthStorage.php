<?php

declare(strict_types=1);

namespace App\ChatGpt;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthRecord;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthStorageInterface;
use Symfony\Component\Uid\Uuid;

final readonly class UserAuthStorage implements AuthStorageInterface
{
    public function __construct(private EncryptedVault $vault, private string $identity)
    {
    }

    public function installationId(): string
    {
        return $this->vault->locked($this->name(), function (): string {
            $data = $this->vault->read($this->name()) ?? ['host' => 'urn:uuid:'.Uuid::v4()->toRfc4122()];
            $this->vault->write($this->name(), $data);
            if (!\is_string($data['host'] ?? null)) {
                throw new \RuntimeException('Invalid connection identity.');
            }

            return $data['host'];
        });
    }

    public function initializeRegistrationHost(string $host): void
    {
        $this->vault->locked($this->name(), function () use ($host): void {
            if (null !== $this->vault->read($this->name())) {
                throw new \LogicException('A fresh connection namespace is required.');
            }
            $this->vault->write($this->name(), ['host' => $host]);
        });
    }

    public function load(): ?AuthRecord
    {
        return $this->vault->locked($this->name(), fn (): ?AuthRecord => $this->record($this->vault->read($this->name()) ?? []));
    }

    public function update(callable $update): AuthRecord
    {
        return $this->vault->locked($this->name(), function () use ($update): AuthRecord {
            $data = $this->vault->read($this->name()) ?? [];
            $record = $update($this->record($data));
            $data['record'] = $record->toArray();
            $this->vault->write($this->name(), $data);

            return $record;
        });
    }

    /** @param array<string, mixed> $data */
    private function record(array $data): ?AuthRecord
    {
        if (!isset($data['record'])) {
            return null;
        }
        if (!\is_array($data['record'])) {
            throw new \RuntimeException('Invalid connection record.');
        }

        return AuthRecord::fromArray($data['record']);
    }

    private function name(): string
    {
        return 'account-'.$this->identity;
    }
}
