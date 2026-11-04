<?php

declare(strict_types=1);

namespace App\Chat;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\Uid\Uuid;

final readonly class ChartImages
{
    public const string ROUTE = 'app_chat_chart';
    public const int MAX_BYTES = 2097152;
    public const int MAX_SIDE = 1600;
    // The hosted renderer uses a 3x device pixel ratio.
    public const int MAX_IMAGE_SIDE = 4800;

    public function __construct(#[Target('charts.storage')] private FilesystemOperator $storage)
    {
    }

    public function copy(string $conversation, string $url): string
    {
        // Only the renderer's observed image CDN, never arbitrary model-provided destinations.
        if (1 !== preg_match('~\Ahttps://mdn\.alipayobjects\.com/one_clip/afts/img/[A-Za-z0-9_-]+/original\z~D', $url)) {
            throw new \DomainException('The chart service returned an unsupported image reference.');
        }
        // Dedicated, untraced client: URLs and image data must not enter the profiler/logs.
        $client = new NoPrivateNetworkHttpClient(HttpClient::create());
        $response = $client->request('GET', $url, ['max_redirects' => 0, 'timeout' => 15, 'max_duration' => 30, 'buffer' => false]);
        try {
            if (200 !== $response->getStatusCode()) {
                throw new \DomainException('The chart image is unavailable.');
            }
            $bytes = '';
            foreach ($client->stream($response) as $chunk) {
                $bytes .= $chunk->getContent();
                if (\strlen($bytes) > self::MAX_BYTES) {
                    throw new \DomainException('The chart image is too large.');
                }
            }
            $size = @getimagesizefromstring($bytes);
            if (false === $size || !\in_array($size[2], [\IMAGETYPE_PNG, \IMAGETYPE_JPEG], true) || $size[0] > self::MAX_IMAGE_SIDE || $size[1] > self::MAX_IMAGE_SIDE) {
                throw new \DomainException('The chart image is not a supported bounded PNG or JPEG.');
            }
            $filename = Uuid::v4()->toRfc4122().(\IMAGETYPE_PNG === $size[2] ? '.png' : '.jpg');
            try {
                $this->storage->write($conversation.'/'.$filename, $bytes);
            } catch (\Throwable $error) {
                $this->storage->delete($conversation.'/'.$filename);
                throw $error;
            }

            return $filename;
        } finally {
            $response->cancel();
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
