<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\AI\Platform\Test\Replay\CassetteHttpClient;
use Symfony\AI\Platform\Test\Replay\HttpCassette;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/** Normalize the conversation UUID and dated system header; retain native matching and SSE parsing. */
final class ReplayChatHttpClient implements HttpClientInterface
{
    private ?CassetteHttpClient $client = null;
    private readonly string $recordedKey;
    private readonly ?string $recordedDateHeader;

    public function __construct(private readonly string $path)
    {
        if (!is_file($path)) {
            throw new \LogicException('The reviewed synthetic chat cassette is missing. Replay cannot record or use the network.');
        }
        $cassette = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
        $body = $cassette['interactions'][0]['request']['body'] ?? null;
        $request = \is_string($body) ? json_decode($body, true, flags: \JSON_THROW_ON_ERROR) : $body;
        $this->recordedKey = $request['prompt_cache_key'] ?? throw new \LogicException('The chat cassette has no conversation cache key.');
        $this->recordedDateHeader = self::dateHeader($request);
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if (null === $this->client) {
            $body = $options['json'] ?? $options['body'] ?? null;
            $request = \is_string($body) ? json_decode($body, true, flags: \JSON_THROW_ON_ERROR) : $body;
            $key = $request['prompt_cache_key'] ?? throw new \LogicException('Chat replay requires an uncompressed JSON request with a cache key.');
            $replacements = [$key => $this->recordedKey];
            $dateHeader = self::dateHeader($request);
            if (null !== $dateHeader && null !== $this->recordedDateHeader) {
                // Replace the full header, never bare dates that could occur in SQL or records.
                $replacements[$dateHeader] = $this->recordedDateHeader;
            }
            $this->client = new CassetteHttpClient(new HttpCassette($this->path, $replacements), record: false);
        }

        return $this->client->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return ($this->client ?? throw new \LogicException('No replay request has been made.'))->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        if ([] !== $options) {
            throw new \LogicException('Chat replay uses the recorded request options.');
        }

        return $this;
    }

    private static function dateHeader(mixed $request): ?string
    {
        $instructions = \is_array($request) ? ($request['instructions'] ?? null) : null;

        return \is_string($instructions) && 1 === preg_match('/Current date \(UTC\): \d{4}-\d{2}-\d{2}\./', $instructions, $matches) ? $matches[0] : null;
    }
}
