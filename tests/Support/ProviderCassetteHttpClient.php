<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\AI\Platform\Test\Replay\CassetteHttpClient;
use Symfony\AI\Platform\Test\Replay\HttpCassette;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClientTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

/** Request selection only: native cassettes own verification, recording and FIFO replay. */
final class ProviderCassetteHttpClient implements HttpClientInterface, ResetInterface
{
    use HttpClientTrait {
        withOptions as private withDefaultOptions;
    }

    private const string CACHE_KEY = '00000000-0000-4000-8000-000000000000';
    private const string DATE_HEADER = 'Current date (UTC): 2000-01-01.';

    /** @var array<string, mixed> */
    private array $defaultOptions = HttpClientInterface::OPTIONS_DEFAULTS;

    /** @var array<string, true> */
    private array $explicitDefaults = [];

    /** @var array<string, CassetteHttpClient> */
    private array $clients = [];

    public function __construct(
        private readonly string $captureDirectory,
        private readonly string $replayDirectory,
        private readonly bool $record = false,
        private readonly ?HttpClientInterface $live = null,
    ) {
        if ($record !== (null !== $live)) {
            throw new \InvalidArgumentException('Recording requires a live client; replay must not receive one.');
        }
        if ('' === $captureDirectory || '' === $replayDirectory || rtrim($captureDirectory, '/') === rtrim($replayDirectory, '/')) {
            throw new \InvalidArgumentException('Provide separate private capture and reviewed replay directories.');
        }
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        // Validate against the complete native option set without passing implicit defaults
        // that would override the real transport's configured timeouts or other defaults.
        $options = array_intersect_key(self::mergeDefaultOptions($options, $this->defaultOptions), $options + $this->explicitDefaults);
        self::assertSupported($options);
        $body = $options['json'] ?? $options['body'] ?? null;
        $replacements = self::replacements($body);
        $identity = self::replace([$method, $url, $options['query'] ?? null, $body], $replacements);
        $key = hash('sha256', json_encode($identity, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        if (!isset($this->clients[$key])) {
            $path = rtrim($this->record ? $this->captureDirectory : $this->replayDirectory, '/').'/'.$key.'.json';
            $filesystem = new Filesystem();
            if ($this->record) {
                if ($filesystem->exists($path)) {
                    throw new \LogicException('A capture already exists for this request. Preserve it before explicitly recording again.');
                }
                $filesystem->mkdir($this->captureDirectory, 0700);
                $filesystem->chmod($this->captureDirectory, 0700);
                $filesystem->dumpFile($path, '{"interactions":[]}');
                $filesystem->chmod($path, 0600);
            } elseif (!is_file($path)) {
                throw new \LogicException('The reviewed provider cassette is missing; replay cannot use the network.');
            }
            $this->clients[$key] = new CassetteHttpClient(new HttpCassette($path, $replacements), $this->live, record: $this->record);
        }

        return $this->clients[$key]->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $client = reset($this->clients);
        if (false === $client) {
            throw new \LogicException('Make a provider request before streaming its response.');
        }

        // Native responses share Symfony's recorded-byte transport, including across selected keys.
        return $client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = $this->withDefaultOptions($options);
        $clone->explicitDefaults = array_fill_keys(array_keys($options), true) + $this->explicitDefaults;

        return $clone;
    }

    public function reset(): void
    {
        $this->clients = [];
    }

    /** @param array<string, mixed> $options */
    private static function assertSupported(array $options): void
    {
        $body = $options['json'] ?? $options['body'] ?? null;
        if (null !== $body && !\is_string($body) && !\is_array($body)) {
            throw new \InvalidArgumentException('Provider cassettes require a string or array body, not a stream or callback.');
        }
        foreach ($options['headers'] ?? [] as $header) {
            if (1 === preg_match('/^content-encoding\s*:\s*(?!identity\s*$)\S/i', $header)) {
                throw new \InvalidArgumentException('Compressed provider requests are unsupported; capture requires uncompressed request bodies.');
            }
        }
        // Invalid UTF-8 and binary/compressed strings cannot safely participate in native replacements.
        json_encode([$options['query'] ?? null, $body], \JSON_THROW_ON_ERROR);
        self::assertPlainValues($body);
    }

    private static function assertPlainValues(mixed $value): void
    {
        if (\is_array($value) || $value instanceof \stdClass) {
            foreach ($value as $item) {
                self::assertPlainValues($item);
            }
        } elseif (null !== $value && !\is_scalar($value)) {
            throw new \InvalidArgumentException('Provider cassette bodies must contain only plain JSON objects, arrays and scalar values.');
        }
    }

    /** @return array<string, string> */
    private static function replacements(mixed $body): array
    {
        $request = \is_string($body) ? json_decode($body, true) : $body;
        $replacements = [];
        if (!\is_array($request)) {
            return $replacements;
        }
        $cacheKey = $request['prompt_cache_key'] ?? null;
        if (\is_string($cacheKey) && 1 === preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/i', $cacheKey)) {
            $replacements[$cacheKey] = self::CACHE_KEY;
        }
        $instructions = $request['instructions'] ?? null;
        if (\is_string($instructions)) {
            preg_match_all('/^Current date \(UTC\): \d{4}-\d{2}-\d{2}\./m', $instructions, $matches);
            foreach ($matches[0] as $header) {
                $replacements[$header] = self::DATE_HEADER;
            }
        }

        return $replacements;
    }

    /** @param array<string, string> $replacements */
    private static function replace(mixed $value, array $replacements): mixed
    {
        if (\is_string($value)) {
            return strtr($value, $replacements);
        }
        if (\is_array($value)) {
            foreach ($value as &$item) {
                $item = self::replace($item, $replacements);
            }
            unset($item);
            if (!array_is_list($value)) {
                ksort($value);
            }
        }

        return $value;
    }
}
