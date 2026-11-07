<?php

declare(strict_types=1);

namespace App\Chat;

use App\ChatGpt\OAuthFactory;
use App\Entity\ChatConversation;
use App\Entity\User;
use App\Finance\Sql\TenantSqlTool;
use App\Repository\ChatConversationRepository;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Bridge\Mcp\ClientToolset;
use Symfony\AI\Agent\Bridge\Mcp\McpToolbox;
use Symfony\AI\Agent\InputProcessor\SystemPromptInputProcessor;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Agent\Toolbox\ChainToolbox;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Factory;
use Symfony\AI\Platform\Bridge\OpenResponses\ModelCatalog;
use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Message\Content\File;
use Symfony\AI\Platform\Message\Template;
use Symfony\AI\Platform\Message\TemplateRenderer\StringTemplateRenderer;
use Symfony\AI\Platform\Platform;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class AgentFactory
{
    public const string PROMPT_FILE = '/prompts/chat.txt';

    public function __construct(
        private OAuthFactory $oauth,
        private TenantSqlTool $tool,
        private StringTemplateRenderer $templates,
        private ChartFiles $files,
        private ChatConversationRepository $conversations,
        private MessageStoreInterface $store,
        #[Autowire(service: 'app.chatgpt.http_client')] private HttpClientInterface $http,
        #[Autowire(service: 'app.charts.http_client')] private HttpClientInterface $chartHttp,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {
    }

    public function create(User $owner, string $identity, string $model, EventDispatcherInterface $events, ChatConversation $conversation, string $message): Agent
    {
        if ('' === $model) {
            throw new \LogicException('Configure a chat model.');
        }
        $catalog = new ModelCatalog([$model => ['class' => ResponsesModel::class, 'capabilities' => [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::THINKING, Capability::TOOL_CALLING]]]);
        $provider = Factory::createProvider($this->oauth->create($identity), $this->http, $catalog);

        $chartGuidelines = $this->templates->render(Template::string(ChartToolset::GUIDELINES), [
            'finance_name' => TenantSqlTool::QUERY_NAME,
        ]);
        $prompt = $this->templates->render(Template::string(File::fromFile($this->projectDir.self::PROMPT_FILE)->asBinary()), [
            'finance_name' => TenantSqlTool::QUERY_NAME,
            'finance_tool' => TenantSqlTool::PROMPT_LINE,
            'finance_guidelines' => TenantSqlTool::GUIDELINES,
            'chart_tool' => ChartToolset::PROMPT_LINE,
            'chart_guidelines' => $chartGuidelines,
            'current_date' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d'),
            'reporting_currency' => $owner->getReportingCurrency()->value,
        ]);

        // Native clients with NullLogger; RPC arguments must not enter logs or the HTTP profiler.
        $factory = new Psr17Factory();
        $chartHttp = new Psr18Client($this->chartHttp, $factory, $factory);
        $chartClient = Client::builder()->setClientInfo('finance-charts', '1.0')->setInitTimeout(10)->setRequestTimeout(45)->setMaxRetries(0)->build();
        $chartTools = new ClientToolset(ChartToolset::NAME, $chartClient, new HttpTransport(ChartToolset::ENDPOINT, httpClient: $chartHttp, requestFactory: $factory, streamFactory: $factory, maxSseBufferBytes: 262144));

        return new Agent(new Platform([$provider]), $model,
            inputProcessors: [new ModelHistoryInputProcessor(), new SystemPromptInputProcessor($prompt)],
            // Keep native NullLogger: debug tool logging would expose SQL arguments.
            toolbox: new FaultTolerantToolbox(new ChainToolbox([
                new Toolbox([$this->tool->forOwner($owner)], eventDispatcher: $events),
                new McpToolbox(new ChartToolset($chartTools, $this->files, $conversation, $message, $this->conversations), eventDispatcher: $events),
            ])),
            messageStore: $this->store,
        );
    }
}
