<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\AI\Platform\Test\Replay\CassetteHttpClient;
use Symfony\AI\Platform\Test\Replay\HttpCassette;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/** Normalize only the conversation UUID; native request matching and SSE parsing stay intact. */
final class ReplayChatHttpClient implements HttpClientInterface
{
    private ?CassetteHttpClient $client = null;
    private readonly string $recordedKey;

    public function __construct(private readonly string $path)
    {
        if (!is_file($path)) {
            throw new \LogicException('The reviewed synthetic chat cassette is missing. Replay cannot record or use the network.');
        }
        $cassette = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
        $body = $cassette['interactions'][0]['request']['body'] ?? null;
        $request = \is_string($body) ? json_decode($body, true, flags: \JSON_THROW_ON_ERROR) : $body;
        $this->recordedKey = $request['prompt_cache_key'] ?? throw new \LogicException('The chat cassette has no conversation cache key.');
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if (null === $this->client) {
            $body = $options['json'] ?? $options['body'] ?? null;
            $request = \is_string($body) ? json_decode($body, true, flags: \JSON_THROW_ON_ERROR) : $body;
            $key = $request['prompt_cache_key'] ?? throw new \LogicException('Chat replay requires an uncompressed JSON request with a cache key.');
            $this->client = new CassetteHttpClient(new HttpCassette($this->path, [$key => $this->recordedKey]), record: false);
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
}
