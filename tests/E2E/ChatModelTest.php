<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Chat\ChartToolset;
use App\Chat\ChatModel;
use App\Chat\ReasoningEffort;
use App\Entity\ChatConversation;
use App\Entity\User;
use App\Message\GenerateChat;
use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;
use Mcp\Schema\Notification\InitializedNotification;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Playwright\Page\PageInterface;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\DefaultClaimsTokenFactory;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
use Twig\Environment;

final class ChatModelTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    private const string TOOL_ID_DELIMITER = '|';
    private const string FUNCTION_CALL = 'function_call';
    private const string FUNCTION_CALL_OUTPUT = 'function_call_output';
    private const string MESSAGE = 'message';
    private const string OUTPUT_TEXT = 'output_text';
    private const string OUTPUT_TEXT_DELTA = 'response.output_text.delta';
    private const string RESPONSE_COMPLETED = 'response.completed';
    private const string OUTPUT_ITEM_DONE = 'response.output_item.done';
    private const string FAILED_CHART_TOOL = 'generate_bar_chart';
    private const string SUBSCRIBER_COOKIE = 'synthetic_mercure';

    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        // Existing synthetic auth only; tests never load credentials or call a real provider.
        $_ENV['CHAT_CASSETTE_REPLAY'] = $_SERVER['CHAT_CASSETTE_REPLAY'] = '1';
        $_ENV['CHATGPT_FINANCE_URL'] = $_SERVER['CHATGPT_FINANCE_URL'] = 'http://selector.browser.finance.localhost';
        try {
            parent::setUp();
        } catch (\Throwable $error) {
            [$_ENV, $_SERVER] = $this->environment;
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            [$_ENV, $_SERVER] = $this->environment;
        }
    }

    public function testChatCapturesEachModelAndReasoningSelection(): void
    {
        $transport = new InMemoryTransport(new PhpSerializer());
        self::getContainer()->set('messenger.transport.chat', $transport);
        $user = FixtureStore::getFixtures()['demo'];
        self::assertInstanceOf(User::class, $user);
        $user->initializeChatGptIdentity();
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->loginUser($user);
        $page = $this->visit('/home/chat');
        $menu = $page->locator('.chat-model-menu summary');
        $menu->click();
        $model = $page->getByLabel('Model', ['exact' => true]);
        $effort = $page->getByRole('slider', ['name' => 'Reasoning level', 'exact' => true]);
        self::assertSame(4, $model->locator('option')->count());
        self::assertSame(ChatModel::Gpt6Luna->value, $model->inputValue());
        self::assertSame('Low', $effort->getAttribute('aria-valuetext'));
        $model->selectOption(ChatModel::Gpt6Sol->value);
        $effort->press('ArrowRight');
        $effort->press('ArrowRight');
        $menu->click();
        $page->getByLabel('Your message', ['exact' => true])->fill('Summarize my monthly spending.');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click());
        self::assertCount(1, $transport->getSent());
        $first = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(GenerateChat::class, $first);
        self::assertSame(ChatModel::Gpt6Sol, $first->model);
        self::assertSame(ReasoningEffort::High, $first->effort);

        $menu->click();
        self::assertSame(ChatModel::Gpt6Sol->value, $model->inputValue());
        self::assertSame('High', $effort->getAttribute('aria-valuetext'));
        $menu->click();
        $page->reload();
        $menu->click();
        self::assertSame(ChatModel::Gpt6Sol->value, $model->inputValue());
        self::assertSame('High', $effort->getAttribute('aria-valuetext'));
        $model->selectOption(ChatModel::Gpt61Sol->value);
        $effort->press('End');
        $effort->press('ArrowLeft');
        self::assertSame('4', $effort->getAttribute('max'));
        $menu->click();
        $page->getByLabel('Your message', ['exact' => true])->fill('And compare it with the previous month.');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click());
        self::assertCount(2, $transport->getSent());
        $second = $transport->getSent()[1]->getMessage();
        self::assertInstanceOf(GenerateChat::class, $second);
        self::assertSame($first->conversationId, $second->conversationId);
        self::assertSame(ChatModel::Gpt61Sol, $second->model);
        self::assertSame(ReasoningEffort::ExtraHigh, $second->effort);
        // A later composer change does not retarget the already serialized first turn.
        $first = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(GenerateChat::class, $first);
        self::assertSame(ChatModel::Gpt6Sol, $first->model);
        self::assertSame(ReasoningEffort::High, $first->effort);
        $page->reload();
        $menu->click();
        self::assertSame(ChatModel::Gpt61Sol->value, $model->inputValue());
        self::assertSame('Extra High', $effort->getAttribute('aria-valuetext'));
        $menu->click();
    }

    public function testMissingModelReturnsFormError(): void
    {
        $page = $this->connectedChat();
        $page->getByLabel('Your message', ['exact' => true])->fill('Summarize my monthly spending.');
        // Keep the real form and CSRF token; omit only the model from the browser POST.
        $page->evaluate('() => { const form = document.querySelector("form.chat-composer"); form.noValidate = true; form.elements.namedItem("model").removeAttribute("name"); }');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->getLastResponse()?->getStatusCode());
        self::assertTrue($page->getByLabel('Your message', ['exact' => true])->isVisible());
    }

    public function testMissingEffortReturnsFormError(): void
    {
        $page = $this->connectedChat();
        $page->getByLabel('Your message', ['exact' => true])->fill('Summarize my monthly spending.');
        // Separate explicitly approved missing-effort case, not a parameterized matrix.
        $page->evaluate('() => { const form = document.querySelector("form.chat-composer"); form.noValidate = true; form.elements.namedItem("effort").removeAttribute("name"); }');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->getLastResponse()?->getStatusCode());
        self::assertTrue($page->getByLabel('Your message', ['exact' => true])->isVisible());
    }

    public function testFocusPreservesActiveLiveCardAndCatchesUpWhenDisconnected(): void
    {
        $this->mockHub();
        $container = self::getContainer();
        $container->set('messenger.transport.chat', new InMemoryTransport(new PhpSerializer()));
        $owner = $this->connectedUser();
        $history = new MessageBag();
        $conversation = new ChatConversation($owner, $history->getId()->toRfc4122());
        $manager = $container->get(EntityManagerInterface::class);
        $manager->persist($conversation);
        $manager->flush();
        $this->loginUser($owner);
        $twig = $container->get(Environment::class);
        $twig->resetGlobals();
        $twig->addGlobal('import_updates_enabled', true);
        $question = Message::ofUser('Synthetic running question.');
        $context = ['conversation' => $conversation, 'user' => $question];
        $start = $twig->render('chat/start.stream.html.twig', $context);
        $tool = new ToolCall('synthetic-live-tool', 'synthetic_tool');
        $toolUpdate = $twig->render('chat/tool.stream.html.twig', [...$context, 'anchor' => $question->getId()->toRfc4122(), 'tool' => $tool, 'result' => null]);
        $page = $this->visit('/home/chat?conversation='.$conversation->id);
        $page->locator('#import-live-subscription turbo-mercure-stream-source')->waitFor(['state' => 'attached']);
        $setup = $page->evaluate(<<<'JS'
            () => {
                const source = document.querySelector('#import-live-subscription turbo-mercure-stream-source');
                if (!source) return {error: 'Native source absent', title: document.title};
                source.es?.close();
                window.chatSnapshotRequests = 0;
                window.chatSnapshotRenders = 0;
                const fetch = window.fetch.bind(window);
                window.fetch = (...args) => {
                    if (new URL(args[0], location.href).pathname === '/home/chat/snapshot') ++window.chatSnapshotRequests;
                    return fetch(...args);
                };
                document.addEventListener('turbo:before-stream-render', event => {
                    const stream = event.detail.newStream;
                    if (!stream.hasAttribute('data-import-snapshot') || stream.getAttribute('target') !== document.querySelector('[data-chat-execution]').id) return;
                    const render = event.detail.render;
                    event.detail.render = async element => { await render(element); ++window.chatSnapshotRenders; };
                });
                // Synthetic native-element state, not evidence of a real Mercure connection.
                source.setAttribute('connected', '');
                return {instrumented: true};
            }
            JS);
        self::assertTrue($setup['instrumented'] ?? false, json_encode($setup, \JSON_THROW_ON_ERROR));
        $page->waitForFunction('() => window.chatSnapshotRenders > 0');
        $baseline = $page->evaluate(<<<'JS'
            () => {
                const root = document.querySelector('[data-chat-execution]');
                window.chatOriginalRoot = root;
                return window.chatSnapshotRequests;
            }
            JS);
        // Agent's initial checkpoint has the question, but not the later live response/tool round.
        $container->get(MessageStoreInterface::class)->save($history->with($question));
        // Actual start Twig/Turbo emits Running before the late HTTP acknowledgement.
        $page->evaluate('html => window.Turbo.renderStreamMessage(html)', $start);
        $page->locator('[data-chat-response-active]')->waitFor();
        self::assertSame('true', $page->locator('form.chat-composer')->getAttribute('data-turbo'));
        $acknowledgements = $page->evaluate(<<<'JS'
            () => {
                const form = document.querySelector('form.chat-composer');
                let acknowledged = 0;
                form.addEventListener('chat-view:acknowledged', () => ++acknowledged, {once: true});
                form.dispatchEvent(new CustomEvent('turbo:submit-end', {bubbles: true, detail: {success: true}}));
                return acknowledged;
            }
            JS);
        self::assertSame(1, $acknowledgements);
        self::assertSame($baseline, $page->evaluate('() => window.chatSnapshotRequests'));
        self::assertSame(1, $page->locator('[data-chat-response-active]')->count());

        // A later real tool stream must still be protected by the original Running marker.
        $page->evaluate('html => window.Turbo.renderStreamMessage(html)', $toolUpdate);
        $card = $page->locator('[data-tool-call-id="synthetic-live-tool"]');
        $card->waitFor();
        // Exercise the declarative action in a real browser, not OS/tab-focus behavior.
        $page->evaluate('() => window.dispatchEvent(new FocusEvent("focus"))');
        self::assertSame($baseline, $page->evaluate('() => window.chatSnapshotRequests'));
        self::assertTrue($card->isVisible());
        self::assertTrue($page->evaluate('() => window.chatOriginalRoot === document.querySelector("[data-chat-execution]")'));

        $page->evaluate('() => { document.querySelector("#import-live-subscription turbo-mercure-stream-source").removeAttribute("connected"); window.dispatchEvent(new FocusEvent("focus")); }');
        $card->waitFor(['state' => 'detached']);
        self::assertSame($baseline + 1, $page->evaluate('() => window.chatSnapshotRequests'));
        self::assertSame(0, $page->locator('[data-chat-response-active]')->count());
        self::assertTrue($page->locator('.chat-live-notice')->isVisible());
    }

    public function testBrowserSubmissionNormalizesForeignToolIdsWithoutRewritingHistory(): void
    {
        $container = self::getContainer();
        $this->mockHub();
        $container->set('app.charts.http_client', new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            $request = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);
            if (InitializedNotification::getMethod() === $request['method']) {
                return new MockResponse('', ['http_code' => Response::HTTP_ACCEPTED]);
            }
            $result = match ($request['method']) {
                InitializeRequest::getMethod() => ['protocolVersion' => $request['params']['protocolVersion'], 'capabilities' => new \stdClass(), 'serverInfo' => ['name' => 'synthetic-charts', 'version' => '1.0']],
                ListToolsRequest::getMethod() => ['tools' => []],
                default => throw new \LogicException('Unexpected synthetic MCP request.'),
            };

            return new MockResponse(json_encode(new \Mcp\Schema\JsonRpc\Response($request['id'], $result), \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
        }));
        $requests = [];
        $container->set('app.chat.recording_http_client', new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);
            $event = ['type' => self::RESPONSE_COMPLETED, 'response' => [
                'id' => 'synthetic-response', 'model' => ChatModel::Gpt6Luna->value,
                'output' => [['type' => self::MESSAGE, 'id' => 'synthetic-answer', 'role' => \Symfony\AI\Platform\Message\Role::Assistant->value, 'content' => [['type' => self::OUTPUT_TEXT, 'text' => 'Synthetic continuation complete.']]]],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
            ]];

            $delta = ['type' => self::OUTPUT_TEXT_DELTA, 'item_id' => 'synthetic-answer', 'output_index' => 0, 'content_index' => 0, 'delta' => 'Synthetic continuation complete.'];

            return new MockResponse('data: '.json_encode($delta, \JSON_THROW_ON_ERROR)."\n\ndata: ".json_encode($event, \JSON_THROW_ON_ERROR)."\n\n", ['response_headers' => ['content-type: text/event-stream']]);
        }));
        $container->set('messenger.transport.chat', new SyncTransport($container->get(MessageBusInterface::class)));
        $user = $this->connectedUser();
        $foreignCall = new ToolCall('foreign-call'.self::TOOL_ID_DELIMITER.'foreign-item', 'synthetic_tool', ['value' => 1], 'foreign-tool-signature');
        $exactCall = new ToolCall('exact-call'.self::TOOL_ID_DELIMITER.'exact-item', 'synthetic_tool', ['value' => 2], 'exact-tool-signature');
        $foreign = new AssistantMessage(new Text('Foreign commentary.'), $foreignCall);
        $foreign->getMetadata()->add('token_usage', new TokenUsage(model: ChatModel::Gpt6Sol->value));
        $exact = new AssistantMessage(new Text('Exact commentary.'), $exactCall);
        $exact->getMetadata()->add('token_usage', new TokenUsage(model: ChatModel::Gpt6Luna->value));
        $history = new MessageBag(Message::ofUser('Synthetic prior question.'), $foreign, Message::ofToolCall($foreignCall, new Text('Foreign result.')), $exact, Message::ofToolCall($exactCall, new Text('Exact result.')), Message::ofAssistant('Prior answer.'));
        $store = $container->get(MessageStoreInterface::class);
        $store->save($history);
        $before = serialize($store->load($history->getId())->getMessages());
        $conversation = new ChatConversation($user, $history->getId()->toRfc4122());
        $manager = $container->get(EntityManagerInterface::class);
        $manager->persist($conversation);
        $manager->flush();
        $this->loginUser($user);
        $page = $this->visit('/home/chat?conversation='.$conversation->id);
        $page->getByLabel('Your message', ['exact' => true])->fill('Continue the synthetic conversation.');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click());
        self::assertCount(1, $requests);
        $calls = array_values(array_filter($requests[0]['input'], static fn (array $item): bool => self::FUNCTION_CALL === ($item['type'] ?? null)));
        $results = array_values(array_filter($requests[0]['input'], static fn (array $item): bool => self::FUNCTION_CALL_OUTPUT === ($item['type'] ?? null)));
        self::assertCount(2, $calls);
        self::assertCount(2, $results);
        self::assertSame('foreign-call', $calls[0]['call_id']);
        self::assertArrayNotHasKey('id', $calls[0]);
        self::assertSame('foreign-call', $results[0]['call_id']);
        self::assertSame('exact-call', $calls[1]['call_id']);
        self::assertSame('exact-item', $calls[1]['id']);
        self::assertSame('exact-call', $results[1]['call_id']);
        self::assertSame('Foreign result.', $results[0]['output']);
        self::assertSame('Exact result.', $results[1]['output']);
        $saved = $store->load($history->getId())->getMessages();
        self::assertSame($before, serialize(\array_slice($saved, 0, $history->count())));
        self::assertSame($foreignCall->getId(), $saved[1]->getToolCalls()[0]->getId());
        self::assertSame($foreignCall->getSignature(), $saved[1]->getToolCalls()[0]->getSignature());
        self::assertSame($exactCall->getId(), $saved[3]->getToolCalls()[0]->getId());
        self::assertSame($exactCall->getSignature(), $saved[3]->getToolCalls()[0]->getSignature());
        // The browser reads the handler's canonical persisted reply, not a direct service result.
        $page->reload();
        self::assertTrue($page->getByText('Synthetic continuation complete.', ['exact' => true])->isVisible());
    }

    public function testFailedChartToolShowsErrorInStreamAndAfterReload(): void
    {
        $container = self::getContainer();
        $updates = [];
        $this->mockHub(static function (Update $update) use (&$updates): string {
            $updates[] = $update->getData();

            return 'synthetic-event';
        });
        $error = 'Synthetic chart input was rejected.';
        $container->set('app.charts.http_client', new MockHttpClient(static function (string $method, string $url, array $options) use ($error): MockResponse {
            $request = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);
            $result = match ($request['method']) {
                \Mcp\Schema\Request\DiscoverRequest::getMethod() => ['supportedVersions' => [\Mcp\Schema\Enum\ProtocolVersion::V2026_07_28->value], 'capabilities' => ['tools' => new \stdClass()]],
                ListToolsRequest::getMethod() => ['tools' => [['name' => self::FAILED_CHART_TOOL, 'description' => 'Draw a synthetic chart.', 'inputSchema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']], 'required' => ['title']]]]],
                CallToolRequest::getMethod() => \Mcp\Schema\Result\CallToolResult::error([new \Mcp\Schema\Content\TextContent($error)]),
                default => throw new \LogicException('Unexpected synthetic MCP request.'),
            };

            return new MockResponse(json_encode(new \Mcp\Schema\JsonRpc\Response($request['id'], $result), \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
        }));
        $round = 0;
        $container->set('app.chat.recording_http_client', new MockHttpClient(static function () use (&$round): MockResponse {
            ++$round;
            if (1 === $round) {
                $output = ['type' => self::FUNCTION_CALL, 'id' => 'synthetic-chart-item', 'call_id' => 'synthetic-chart-call', 'name' => ChartToolset::NAME.'_'.self::FAILED_CHART_TOOL, 'arguments' => json_encode(['title' => 'Synthetic spending'], \JSON_THROW_ON_ERROR)];
                $delta = ['type' => self::OUTPUT_ITEM_DONE, 'output_index' => 0, 'item' => $output];
            } else {
                $output = ['type' => self::MESSAGE, 'id' => 'synthetic-answer', 'role' => \Symfony\AI\Platform\Message\Role::Assistant->value, 'content' => [['type' => self::OUTPUT_TEXT, 'text' => 'The chart could not be generated.']]];
                $delta = ['type' => self::OUTPUT_TEXT_DELTA, 'item_id' => 'synthetic-answer', 'output_index' => 0, 'content_index' => 0, 'delta' => 'The chart could not be generated.'];
            }
            $completed = ['type' => self::RESPONSE_COMPLETED, 'response' => ['id' => 'synthetic-response-'.$round, 'model' => ChatModel::Gpt6Luna->value, 'output' => [$output], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2]]];

            return new MockResponse('data: '.json_encode($delta, \JSON_THROW_ON_ERROR)."\n\ndata: ".json_encode($completed, \JSON_THROW_ON_ERROR)."\n\n", ['response_headers' => ['content-type: text/event-stream']]);
        }));
        $container->set('messenger.transport.chat', new SyncTransport($container->get(MessageBusInterface::class)));
        $owner = $this->connectedUser();
        $history = new MessageBag();
        $conversation = new ChatConversation($owner, $history->getId()->toRfc4122());
        $manager = $container->get(EntityManagerInterface::class);
        $manager->persist($conversation);
        $manager->flush();
        $this->loginUser($owner);
        $page = $this->visit('/home/chat?conversation='.$conversation->id);
        $page->getByLabel('Your message', ['exact' => true])->fill('Draw a synthetic spending chart.');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click());
        self::assertSame(2, $round);
        self::assertTrue(str_contains(implode('', $updates), 'Tool call failed'), 'The handler must publish the explicit tool failure.');

        // SyncTransport has already completed. Replay only actual handler publications up to
        // the failed card through native Turbo: this proves streamed HTML, not network timing.
        $page->locator('.chat-turns')->evaluate('element => element.replaceChildren()');
        foreach ($updates as $update) {
            $page->evaluate('async html => { window.Turbo.renderStreamMessage(html); await new Promise(requestAnimationFrame); }', $update);
            if (str_contains($update, 'Tool call failed')) {
                break;
            }
        }
        $card = $page->locator('.chat-tool-error');
        $card->waitFor();
        self::assertTrue($page->locator('[data-chat-response-active]')->isVisible());
        self::assertSame(\Symfony\AI\Agent\Execution\ExecutionStatus::Failed->value, $card->getAttribute('data-tool-status'));
        self::assertSame('Tool call failed', $card->locator('.chat-tool-label')->textContent());
        $card->locator('summary')->click();
        self::assertTrue($card->locator('.chat-tool-result')->isVisible());
        self::assertStringContainsString($error, $card->locator('.chat-tool-result')->textContent());
        self::assertStringContainsString('Synthetic spending', $card->locator('.chat-tool-arguments')->textContent());
        self::assertSame(ChartToolset::NAME.'_'.self::FAILED_CHART_TOOL, $card->locator('code')->textContent());

        $page->reload();
        $card = $page->locator('.chat-tool-error');
        $card->waitFor();
        self::assertSame('Tool call failed', $card->locator('.chat-tool-label')->textContent());
        $card->locator('summary')->click();
        self::assertTrue($card->locator('.chat-tool-result')->isVisible());
        self::assertStringContainsString($error, $card->locator('.chat-tool-result')->textContent());
        self::assertTrue($page->getByText('The chart could not be generated.', ['exact' => true])->isVisible());
    }

    private function connectedChat(): PageInterface
    {
        self::getContainer()->set('messenger.transport.chat', new InMemoryTransport(new PhpSerializer()));
        $this->loginUser($this->connectedUser());

        return $this->visit('/home/chat');
    }

    private function connectedUser(): User
    {
        $fixture = FixtureStore::getFixtures()['demo'];
        self::assertInstanceOf(User::class, $fixture);
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $manager->find(User::class, $fixture->getId());
        self::assertInstanceOf(User::class, $user);
        $user->initializeChatGptIdentity();
        $manager->flush();

        return $user;
    }

    private function mockHub(?\Closure $publisher = null): void
    {
        $container = self::getContainer();
        $factory = new DefaultClaimsTokenFactory(new LcobucciFactory(bin2hex(random_bytes(32))), ['iss' => $this->getBaseUrl(), 'aud' => $this->getBaseUrl().'/.well-known/mercure', 'sub' => 'synthetic', 'client_id' => 'synthetic']);
        $container->set(HubInterface::class, new MockHub($this->getBaseUrl().'/.well-known/mercure', new StaticTokenProvider('synthetic-token'), $publisher ?? static fn (): string => 'synthetic-event', $factory, cookieName: self::SUBSCRIBER_COOKIE));
    }
}
