<?php

declare(strict_types=1);

namespace App\Chat;

use App\ChatGpt\OAuthFactory;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\InputProcessor\SystemPromptInputProcessor;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Factory;
use Symfony\AI\Platform\Bridge\OpenResponses\ModelCatalog;
use Symfony\AI\Platform\Platform;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Environment;

final readonly class AgentFactory
{
    public const string PROMPT_TEMPLATE = 'chat/system_prompt.txt.twig';

    public function __construct(
        private OAuthFactory $oauth,
        #[Autowire(service: 'app.chatgpt.http_client')] private HttpClientInterface $http,
        #[Autowire(service: 'app.chat.model_catalog')] private ModelCatalog $catalog,
        private Environment $twig,
    ) {
    }

    public function create(string $identity, string $model, NativeAssistantOutput $output): Agent
    {
        if ('' === $model) {
            throw new \LogicException('Configure a chat model.');
        }
        $provider = Factory::createProvider($this->oauth->create($identity), $this->http, $this->catalog);

        return new Agent(new Platform([$provider]), $model,
            inputProcessors: [new SystemPromptInputProcessor($this->twig->render(self::PROMPT_TEMPLATE))],
            outputProcessors: [$output],
        );
    }
}
