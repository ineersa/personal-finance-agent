<?php

declare(strict_types=1);

namespace App\Chat;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Uid\Uuid;

/** Private retained chart documents and historical images, not a public artifact store. */
final readonly class ChartFiles
{
    public const string ROUTE = 'app_chat_chart';
    public const string OUTPUT_DIRECTORY = '/chart-output';
    public const int MAX_BYTES = 2097152;
    public const int MAX_SIDE = 1600;
    public const string HTML_CSP = "sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; connect-src 'none'; object-src 'none'; frame-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'";

    public function __construct(#[Target('charts.storage')] private FilesystemOperator $storage)
    {
    }

    public function outputPath(): string
    {
        return self::OUTPUT_DIRECTORY.'/'.Uuid::v4()->toRfc4122().'.html';
    }

    public function retain(string $conversation, string $path): string
    {
        if (is_link($path) || !is_file($path) || self::OUTPUT_DIRECTORY !== \dirname($path) || realpath($path) !== $path) {
            throw new \DomainException('The chart document is unavailable.');
        }
        $stream = fopen($path, 'rb');
        if (false === $stream) {
            throw new \RuntimeException('Cannot open chart document.');
        }
        try {
            $html = stream_get_contents($stream, self::MAX_BYTES + 1);
            if (false === $html || '' === $html || \strlen($html) > self::MAX_BYTES) {
                throw new \DomainException('The chart document exceeds its resource limit.');
            }
            $filename = Uuid::v4()->toRfc4122().'.html';
            try {
                $this->storage->write($conversation.'/'.$filename, $html);
            } catch (\Throwable $error) {
                $this->storage->delete($conversation.'/'.$filename);
                throw $error;
            }

            return $filename;
        } finally {
            fclose($stream);
        }
    }

    /** @return resource */
    public function read(string $conversation, string $filename)
    {
        return $this->storage->readStream($conversation.'/'.$filename);
    }

    public function removeConversation(string $conversation): void
    {
        $this->storage->deleteDirectory($conversation);
    }

    public function reset(): void
    {
        $this->storage->deleteDirectory('');
    }
}
