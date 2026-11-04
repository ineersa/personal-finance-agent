<?php

declare(strict_types=1);

namespace App\ChatGpt;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthFileStore;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthStorageInterface;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\IdTokenVerifier;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthConfig;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthService;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OAuthFactory
{
    public function __construct(
        #[Autowire(service: 'app.chatgpt.http_client')] private HttpClientInterface $http,
        private IdTokenVerifier $verifier,
        private OAuthConfig $config,
        private Filesystem $filesystem,
        private LockFactory $locks,
        private ClockInterface $clock,
        #[Autowire('%kernel.project_dir%/var/private/chatgpt-auth')] private string $directory,
        #[Autowire('%app.chatgpt.installation_id%')] private string $installationId,
        private ?AuthStorageInterface $storageOverride = null,
    ) {
    }

    public function storage(string $identity): AuthStorageInterface
    {
        if (null !== $this->storageOverride) {
            return $this->storageOverride;
        }
        // Separate from the previous encrypted store; existing credentials are never migrated.
        $this->filesystem->mkdir($this->directory, 0700);

        return new AuthFileStore($this->directory.'/'.$identity.'.json', $this->locks, $this->filesystem, $this->installationId);
    }

    public function create(string $identity): OAuthService
    {
        return new OAuthService($this->storage($identity), $this->http, $this->verifier, $this->config, $this->clock);
    }
}
