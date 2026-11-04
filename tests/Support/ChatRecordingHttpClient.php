<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\AI\Platform\Test\Replay\CassetteHttpClient;
use Symfony\AI\Platform\Test\Replay\HttpCassette;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ChatRecordingHttpClient
{
    public static function create(?HttpClientInterface $live, Filesystem $filesystem, string $path, bool $record, bool $replay, string $replayPath): HttpClientInterface
    {
        if ($record && $replay) {
            throw new \LogicException('Select either chat recording or replay, not both.');
        }
        if ($replay) {
            return new ReplayChatHttpClient($replayPath);
        }
        $live ??= throw new \LogicException('Live chat requires an inference HTTP client.');
        if (!$record) {
            return $live;
        }
        if ($filesystem->exists($path)) {
            throw new \LogicException('The private chat cassette already exists. Preserve it for inspection before recording again.');
        }
        $filesystem->mkdir(\dirname($path), 0700);
        $filesystem->chmod(\dirname($path), 0700);
        $filesystem->dumpFile($path, '{"interactions":[]}');
        $filesystem->chmod($path, 0600);

        return new CassetteHttpClient(new HttpCassette($path), $live, record: true);
    }
}
