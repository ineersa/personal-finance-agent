<?php

declare(strict_types=1);

namespace App\Chat;

use App\ChatGpt\OAuthFactory;
use App\Entity\User;
use App\Finance\Sql\TenantSqlToolFactory;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\InputProcessor\SystemPromptInputProcessor;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Factory;
use Symfony\AI\Platform\Bridge\OpenResponses\ModelCatalog;
use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Message\Content\File;
use Symfony\AI\Platform\Platform;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class AgentFactory
{
    public const string PROMPT_FILE = '/prompts/chat.txt';

    public function __construct(
        private OAuthFactory $oauth,
        private TenantSqlToolFactory $tools,
        #[Autowire(service: 'app.chatgpt.http_client')] private HttpClientInterface $http,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {
    }

    public function create(User $owner, string $identity, string $model, NativeAssistantOutput $output, EventDispatcherInterface $events): Agent
    {
        if ('' === $model) {
            throw new \LogicException('Configure a chat model.');
        }
        $catalog = new ModelCatalog([$model => ['class' => ResponsesModel::class, 'capabilities' => [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::THINKING, Capability::TOOL_CALLING]]]);
        $provider = Factory::createProvider($this->oauth->create($identity), $this->http, $catalog);

        return new Agent(new Platform([$provider]), $model,
            inputProcessors: [new SystemPromptInputProcessor(File::fromFile($this->projectDir.self::PROMPT_FILE))],
            outputProcessors: [$output],
            toolbox: $this->tools->create($owner, $events),
        );
    }
}
