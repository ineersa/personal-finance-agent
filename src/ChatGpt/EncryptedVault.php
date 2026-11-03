<?php

declare(strict_types=1);

namespace App\ChatGpt;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class EncryptedVault
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/private/chatgpt')] private readonly string $directory,
        #[Autowire('%env(CHATGPT_KEY_FILE)%')] private readonly string $keyFile,
    ) {
    }

    public function available(): bool
    {
        return '' !== $this->keyFile && is_file($this->keyFile);
    }

    /** @template T
     * @param callable(): T $action
     *
     * @return T
     */
    public function locked(string $name, callable $action): mixed
    {
        if (is_link($this->directory)) {
            throw new \RuntimeException('Refusing symlinked connection storage.');
        }
        (new Filesystem())->mkdir($this->directory, 0700);
        $lock = (new LockFactory(new FlockStore($this->directory)))->createLock($name);
        $lock->acquire(true);
        try {
            return $action();
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed>|null */
    public function read(string $name): ?array
    {
        $path = $this->path($name);
        if (!is_file($path)) {
            return null;
        }
        $bytes = file_get_contents($path);
        if (false === $bytes || \strlen($bytes) < 28) {
            throw new \RuntimeException('Cannot read encrypted connection.');
        }
        $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $this->key(), \OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), $name);
        if (false === $plain) {
            throw new \RuntimeException('Cannot decrypt connection.');
        }
        $data = json_decode($plain, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($data) || array_is_list($data)) {
            throw new \RuntimeException('Invalid encrypted connection.');
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public function write(string $name, #[\SensitiveParameter] array $data): void
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(json_encode($data, \JSON_THROW_ON_ERROR), 'aes-256-gcm', $this->key(), \OPENSSL_RAW_DATA, $nonce, $tag, $name);
        if (false === $ciphertext) {
            throw new \RuntimeException('Cannot encrypt connection.');
        }
        $filesystem = new Filesystem();
        $temporary = $filesystem->tempnam($this->directory, 'grant-');
        try {
            $filesystem->chmod($temporary, 0600);
            if (false === file_put_contents($temporary, $nonce.$tag.$ciphertext)) {
                throw new \RuntimeException('Cannot save encrypted connection.');
            }
            $filesystem->rename($temporary, $this->path($name), true);
        } finally {
            $filesystem->remove($temporary);
        }
    }

    public function remove(string $name): void
    {
        (new Filesystem())->remove($this->path($name));
    }

    private function path(string $name): string
    {
        $path = $this->directory.'/'.hash('sha256', $name).'.enc';
        if (is_link($path)) {
            throw new \RuntimeException('Refusing symlinked connection record.');
        }

        return $path;
    }

    private function key(): string
    {
        $key = file_get_contents($this->keyFile);
        if (false === $key || 32 !== \strlen($key)) {
            throw new \RuntimeException('ChatGPT encryption key is not configured.');
        }

        return $key;
    }
}
