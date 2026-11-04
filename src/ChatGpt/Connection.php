<?php

declare(strict_types=1);

namespace App\ChatGpt;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthException;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthorizationRequestDTO;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthConfig;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class Connection
{
    public const string CALLBACK_PATH = '/auth/callback';
    public const string CALLBACK_HOST = '127.0.0.1';
    public const string CONNECT_ROUTE = 'app_chatgpt_connect';
    public const string DISCONNECT_ROUTE = 'app_chatgpt_disconnect';
    public const string CONNECT_CSRF = 'chatgpt-connect';
    public const string DISCONNECT_CSRF = 'chatgpt-disconnect';
    public const string SETTINGS_ROUTE = 'admin_settings';
    public const string SETTINGS_FRAGMENT = 'chatgpt-connection';
    private const int ATTEMPT_LIFETIME = 300;
    private const string STATE_PARAMETER = 'state';
    private const string ADMIN_ROLE = 'ROLE_ADMIN';

    public function __construct(
        private OAuthFactory $oauth,
        private UserRepository $users,
        private EntityManagerInterface $em,
        private Filesystem $filesystem,
        private LockFactory $locks,
        private ClockInterface $clock,
        private UrlGeneratorInterface $router,
        #[Autowire('%kernel.project_dir%/var/private/chatgpt-attempts')] private string $attemptDirectory,
        #[Autowire('%env(CHATGPT_FINANCE_URL)%')] private string $financeUrl,
    ) {
    }

    public function status(User $user): ConnectionStatus
    {
        if ('' === $this->financeUrl) {
            return ConnectionStatus::Unavailable;
        }
        $identity = $user->getChatGptIdentity();
        if (null === $identity) {
            return ConnectionStatus::Disconnected;
        }
        try {
            $record = $this->oauth->storage($identity)->load();
            if (null === $record || ([] === $record->scopes && null === $record->pendingRefresh)) {
                return ConnectionStatus::Disconnected;
            }
            if (!\in_array(OAuthConfig::DIRECT_SCOPE, $record->scopes, true)) {
                return ConnectionStatus::Permission;
            }
            $this->oauth->create($identity)->accessToken();

            return ConnectionStatus::Connected;
        } catch (AuthException) {
            return ConnectionStatus::Reconnect;
        }
    }

    public function begin(User $user): string
    {
        if ('' === $this->financeUrl) {
            throw new \LogicException('Local ChatGPT connection is not configured.');
        }
        $owner = $user->getId() ?? throw new \LogicException('An application account is required.');
        $identity = $user->initializeChatGptIdentity();
        $this->em->flush();
        $request = $this->oauth->create($identity)->beginAuthorization(true);
        $this->filesystem->mkdir($this->attemptDirectory, 0700);
        $this->filesystem->chmod($this->attemptDirectory, 0700);
        // Only the cross-host handoff is application-owned; the package owns all OAuth state.
        $path = $this->attemptPath($request->state);
        $this->filesystem->dumpFile($path, json_encode([
            'owner' => $owner,
            'identity' => $identity,
            'expires' => $this->clock->now()->getTimestamp() + self::ATTEMPT_LIFETIME,
            'request' => new AuthorizationRequestDTO('', $request->state, $request->nonce, $request->verifier, $request->registeredClientId),
        ], \JSON_THROW_ON_ERROR));
        $this->filesystem->chmod($path, 0600);

        return $request->url;
    }

    /** @param array<string, mixed> $callback */
    public function complete(#[\SensitiveParameter] array $callback): ConnectionStatus
    {
        $state = $callback[self::STATE_PARAMETER] ?? null;
        if (!\is_string($state) || '' === $state) {
            throw new AuthException('Invalid authorization attempt.');
        }
        $path = $this->attemptPath($state);
        $lock = $this->locks->createLock('chatgpt.attempt.'.hash('sha256', $state));
        $lock->acquire(true);
        try {
            if (!$this->filesystem->exists($path)) {
                throw new AuthException('Authorization attempt expired or already consumed.');
            }
            $contents = $this->filesystem->readFile($path);
            $this->filesystem->remove($path);
        } finally {
            $lock->release();
        }
        $attempt = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        if ($attempt['expires'] <= $this->clock->now()->getTimestamp()) {
            throw new AuthException('Authorization attempt expired.');
        }
        $user = $this->users->find($attempt['owner']);
        if (null === $user || $attempt['identity'] !== $user->getChatGptIdentity() || \in_array(self::ADMIN_ROLE, $user->getRoles(), true)) {
            throw new AuthException('Connection owner is no longer available.');
        }
        $request = $attempt['request'];
        $this->oauth->create($attempt['identity'])->completeAuthorization(new AuthorizationRequestDTO(
            '', $request['state'], $request['nonce'], $request['verifier'], $request['registeredClientId'],
        ), $callback);

        return $this->status($user);
    }

    public function disconnect(User $user): void
    {
        $identity = $user->getChatGptIdentity();
        if (null !== $identity) {
            // Revoke first: failure preserves the existing grant and account namespace.
            $this->oauth->create($identity)->disconnect();
            $user->renewChatGptIdentity();
            $this->em->flush();
            // A fresh package store permits another account; old pending attempts no longer match.
        }
    }

    public function displayIdentity(User $user): ?string
    {
        $identity = $user->getChatGptIdentity();
        if (null === $identity) {
            return null;
        }
        $record = $this->oauth->storage($identity)->load();
        if (null === $record?->idToken || null === $record->access || null !== $record->pendingRefresh) {
            return null;
        }
        // Display only: OAuthService verified this exact saved token, not incoming or pending data.
        $claims = json_decode(JWT::urlsafeB64Decode(explode('.', $record->idToken)[1]), true, flags: \JSON_THROW_ON_ERROR);
        foreach (['email', 'name'] as $field) {
            if (\is_string($claims[$field] ?? null) && '' !== trim($claims[$field])) {
                return $claims[$field];
            }
        }

        return null;
    }

    public function returnUrl(): string
    {
        return rtrim($this->financeUrl, '/').$this->router->generate(self::SETTINGS_ROUTE, ['_fragment' => self::SETTINGS_FRAGMENT]);
    }

    private function attemptPath(string $state): string
    {
        return $this->attemptDirectory.'/'.hash('sha256', $state).'.json';
    }
}
