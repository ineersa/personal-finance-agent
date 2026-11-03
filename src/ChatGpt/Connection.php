<?php

declare(strict_types=1);

namespace App\ChatGpt;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthorizationRequestDTO;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\IdTokenVerifier;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthConfig;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\HttpClient;

final readonly class Connection
{
    public function __construct(
        private EncryptedVault $vault,
        private UserRepository $users,
        private EntityManagerInterface $em,
        #[Autowire('%env(int:CHATGPT_CALLBACK_PORT)%')] private int $port,
        #[Autowire('%env(CHATGPT_FINANCE_URL)%')] private string $financeUrl,
    ) {
    }

    public function available(): bool
    {
        return $this->port >= 1024 && '' !== $this->financeUrl && $this->vault->available();
    }

    public function status(User $user): string
    {
        if (!$this->available()) {
            return 'unavailable';
        }
        try {
            $identity = $user->getChatGptIdentity();
            if (null === $identity) {
                return 'disconnected';
            }
            $record = (new UserAuthStorage($this->vault, $identity))->load();
            if (null === $record || ([] === $record->scopes && null === $record->pendingRefresh)) {
                return 'disconnected';
            }
            if (!\in_array(OAuthConfig::DIRECT_SCOPE, $record->scopes, true)) {
                return 'permission';
            }
            $this->oauth($identity)->accessToken();

            return 'connected';
        } catch (\Throwable) {
            return 'reconnect';
        }
    }

    public function begin(User $user): string
    {
        if (!$this->available()) {
            throw new \RuntimeException('Local ChatGPT connection is not configured.');
        }
        $owner = $user->getId() ?? throw new \LogicException('An application account is required.');
        $identity = $user->initializeChatGptIdentity();
        // Persist the account-instance namespace before any provider communication.
        $this->em->flush();
        $request = $this->oauth($identity)->beginAuthorization(true);
        $name = 'attempt-'.$request->state;
        $this->vault->locked($name, function () use ($name, $owner, $identity, $request): void {
            $this->vault->write($name, [
                'owner' => $owner,
                'identity' => $identity,
                'expires' => time() + 300,
                'state' => $request->state,
                'nonce' => $request->nonce,
                'verifier' => $request->verifier,
                'client' => $request->registeredClientId,
            ]);
        });

        return $request->url;
    }

    /** @param array<string, mixed> $callback */
    public function complete(#[\SensitiveParameter] array $callback): string
    {
        $state = $callback['state'] ?? null;
        if (!\is_string($state) || 1 !== preg_match('/^[a-zA-Z0-9_-]{16,256}$/D', $state)) {
            throw new \RuntimeException('Invalid authorization attempt.');
        }
        $name = 'attempt-'.$state;
        $attempt = $this->vault->locked($name, function () use ($name): ?array {
            $attempt = $this->vault->read($name);
            $this->vault->remove($name);

            return $attempt;
        });
        if (null === $attempt || !\is_int($attempt['owner'] ?? null) || !\is_int($attempt['expires'] ?? null) || $attempt['expires'] < time()) {
            throw new \RuntimeException('Authorization attempt expired.');
        }
        if (null !== ($attempt['client'] ?? null) && !\is_string($attempt['client'])) {
            throw new \RuntimeException('Invalid authorization registration.');
        }
        $user = $this->users->find($attempt['owner']);
        $identity = $this->stringValue($attempt, 'identity');
        if (null === $user || $identity !== $user->getChatGptIdentity() || \in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            throw new \RuntimeException('Connection owner is no longer available.');
        }
        $request = new AuthorizationRequestDTO('', $this->stringValue($attempt, 'state'), $this->stringValue($attempt, 'nonce'), $this->stringValue($attempt, 'verifier'), $attempt['client'] ?? null);
        $this->oauth($identity)->completeAuthorization($request, $callback);

        return $this->status($user);
    }

    public function disconnect(User $user): void
    {
        $identity = $user->getChatGptIdentity();
        if (null !== $identity) {
            $storage = new UserAuthStorage($this->vault, $identity);
            $host = $storage->installationId();
            // A failed revoke throws before namespace changes: retain the existing connection.
            $this->oauth($identity)->disconnect();
            $freshIdentity = $user->renewChatGptIdentity();
            (new UserAuthStorage($this->vault, $freshIdentity))->initializeRegistrationHost($host);
            $this->em->flush();
            // Fresh registration permits another account; old pending attempts fail owner binding.
        }
    }

    public function displayIdentity(User $user): ?string
    {
        $identity = $user->getChatGptIdentity();
        if (null === $identity) {
            return null;
        }
        try {
            $record = (new UserAuthStorage($this->vault, $identity))->load();
            if (null === $record?->idToken || null === $record->access || null !== $record->pendingRefresh || OAuthConfig::ISSUER !== $record->issuer || null === $record->subject) {
                return null;
            }
            // Display only: this exact saved token was verified by OAuthService before persistence.
            // Never decode a callback token or the unverified pendingRefresh token for identity.
            $parts = explode('.', $record->idToken);
            if (3 !== \count($parts)) {
                return null;
            }
            $claims = json_decode(JWT::urlsafeB64Decode($parts[1]), true, flags: \JSON_THROW_ON_ERROR);
            if (!\is_array($claims) || ($claims['iss'] ?? null) !== $record->issuer || ($claims['sub'] ?? null) !== $record->subject) {
                return null;
            }
            foreach (['email', 'name'] as $field) {
                if (\is_string($claims[$field] ?? null) && '' !== trim($claims[$field])) {
                    return $claims[$field];
                }
            }
        } catch (\Throwable) {
            // Missing display claims must not affect authorization or expose token details.
        }

        return null;
    }

    public function returnUrl(): string
    {
        return rtrim($this->financeUrl, '/').'/home/settings#chatgpt-connection';
    }

    /** @param array<string, mixed> $data */
    private function stringValue(array $data, string $key): string
    {
        if (!\is_string($data[$key] ?? null)) {
            throw new \RuntimeException('Invalid authorization attempt.');
        }

        return $data[$key];
    }

    private function oauth(string $identity): OAuthService
    {
        // Native client: tokens and provider exchanges never enter the framework profiler.
        $http = HttpClient::create(['timeout' => 15, 'max_duration' => 30]);

        return new OAuthService(new UserAuthStorage($this->vault, $identity), $http, new IdTokenVerifier($http), new OAuthConfig('Personal finance assistant', $this->port));
    }
}
