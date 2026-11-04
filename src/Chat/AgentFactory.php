<?php

declare(strict_types=1);

namespace App\Chat;

use App\ChatGpt\OAuthFactory;
use App\Entity\User;
use App\Finance\Sql\TenantSqlTool;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\InputProcessor\SystemPromptInputProcessor;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Factory;
use Symfony\AI\Platform\Bridge\OpenResponses\ModelCatalog;
use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Platform;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class AgentFactory
{
    public const string PROMPT_FILE = '/prompts/chat.txt';

    public function __construct(
        private OAuthFactory $oauth,
        private TenantSqlTool $tool,
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

        $prompt = file_get_contents($this->projectDir.self::PROMPT_FILE);
        if (false === $prompt) {
            throw new \RuntimeException('Chat prompt is unavailable.');
        }
        $prompt = strtr($prompt, ['{finance_name}' => TenantSqlTool::QUERY_NAME, '{finance_tool}' => TenantSqlTool::PROMPT_LINE, '{finance_guidelines}' => TenantSqlTool::GUIDELINES]);

        return new Agent(new Platform([$provider]), $model,
            inputProcessors: [new SystemPromptInputProcessor($prompt)],
            outputProcessors: [$output],
            // Keep native NullLogger: debug tool logging would expose SQL arguments.
            toolbox: new Toolbox([$this->tool->forOwner($owner)], eventDispatcher: $events),
        );
    }
}
