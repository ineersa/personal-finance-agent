<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;

final class DemoWorkspaceTest extends FinanceBrowserTest
{
    public function testChatConnectionGate(): void
    {
        $this->loginUser(FixtureStore::getFixtures()['demo']);
        $page = $this->visit('/home/chat');
        self::assertResponseIsSuccessful();
        self::assertTrue($page->getByRole('heading', ['name' => 'Make sense of your money.', 'exact' => true])->isVisible());
        self::assertTrue($page->getByRole('button', ['name' => 'Continue with ChatGPT', 'exact' => true])->isVisible());
        self::assertTrue($page->getByText('Connect ChatGPT to start a conversation.', ['exact' => false])->isVisible());
    }

    public function testConversationHistoryControls(): void
    {
        $this->loginUser(FixtureStore::getFixtures()['demo']);
        $page = $this->visit('/home/chat');
        $page->getByRole('button', ['name' => 'New chat', 'exact' => true])->click();
        $page->getByRole('button', ['name' => 'Save chat', 'exact' => true])->waitFor();
        $saved = $page->locator('#chat-history option[selected]')->getAttribute('value');
        self::assertNotEmpty($saved);
        $page->getByRole('button', ['name' => 'Save chat', 'exact' => true])->click();
        $page->getByText('Saved chat', ['exact' => true])->waitFor();

        $page->getByRole('button', ['name' => 'New chat', 'exact' => true])->click();
        $page->getByRole('button', ['name' => 'Save chat', 'exact' => true])->waitFor();
        self::assertSame(2, $page->locator('#chat-history option')->count());
        $page->getByLabel('Chat history', ['exact' => true])->selectOption($saved);
        $page->getByRole('button', ['name' => 'Open chat', 'exact' => true])->click();
        $page->getByText('Saved chat', ['exact' => true])->waitFor();
        $page->reload();
        self::assertTrue($page->getByText('Saved chat', ['exact' => true])->isVisible());
        self::assertSame($saved, $page->locator('#chat-history option[selected]')->getAttribute('value'));
    }
}
