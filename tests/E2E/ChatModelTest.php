<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Chat\ChatModel;
use App\Chat\ReasoningEffort;
use App\Entity\User;
use App\Message\GenerateChat;
use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final class ChatModelTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        // Existing replay auth only; no inference or cassette response is requested by this journey.
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
        $model = $page->getByLabel('Model', ['exact' => true]);
        $effort = $page->getByLabel('Reasoning level', ['exact' => true]);
        self::assertSame(ChatModel::Gpt6Luna->value, $model->inputValue());
        self::assertSame(ReasoningEffort::Low->value, $effort->inputValue());
        $model->selectOption(ChatModel::Gpt6Sol->value);
        $effort->selectOption(ReasoningEffort::High->value);
        $page->getByLabel('Your message', ['exact' => true])->fill('Summarize my monthly spending.');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click());
        self::assertCount(1, $transport->getSent());
        $first = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(GenerateChat::class, $first);
        self::assertSame(ChatModel::Gpt6Sol, $first->model);
        self::assertSame(ReasoningEffort::High, $first->effort);

        $model->selectOption(ChatModel::Gpt53CodexSpark->value);
        $effort->selectOption(ReasoningEffort::ExtraHigh->value);
        self::assertSame(2, $effort->locator('option:enabled')->count());
        $page->getByLabel('Your message', ['exact' => true])->fill('And compare it with the previous month.');
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'Send message', 'exact' => true])->click());
        self::assertCount(2, $transport->getSent());
        $second = $transport->getSent()[1]->getMessage();
        self::assertInstanceOf(GenerateChat::class, $second);
        self::assertSame($first->conversationId, $second->conversationId);
        self::assertSame(ChatModel::Gpt53CodexSpark, $second->model);
        self::assertSame(ReasoningEffort::ExtraHigh, $second->effort);
        // A later composer change does not retarget the already serialized first turn.
        $first = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(GenerateChat::class, $first);
        self::assertSame(ChatModel::Gpt6Sol, $first->model);
        self::assertSame(ReasoningEffort::High, $first->effort);
    }
}
