<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthRecord;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthStorageInterface;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthConfig;
use Symfony\Component\HttpClient\MockHttpClient;

/** Synthetic replay-only state: never loads or saves package credential files. */
final class ReplayChatAuthStorage implements AuthStorageInterface
{
    public function installationId(): string
    {
        throw new \LogicException('OAuth authorization is unavailable during cassette replay.');
    }

    public function load(): AuthRecord
    {
        return new AuthRecord('synthetic-client', 'synthetic-access', 'synthetic-refresh', time() + 3600,
            'synthetic.e30.synthetic', OAuthConfig::ISSUER, 'synthetic-subject', [OAuthConfig::DIRECT_SCOPE], 'synthetic-nonce');
    }

    public function update(callable $update): AuthRecord
    {
        return $update($this->load());
    }

    public static function noNetwork(): MockHttpClient
    {
        return new MockHttpClient(static fn () => throw new \LogicException('OAuth network access is unavailable during cassette replay.'));
    }
}
