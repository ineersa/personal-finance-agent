<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\FinanceBrowserTest;
use App\Tests\Support\SubmitsBrowserForms;
use Hautelook\AliceBundle\PhpUnit\FixtureStore;

final class DemoWorkspaceTest extends FinanceBrowserTest
{
    use SubmitsBrowserForms;

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
        $this->submit(static fn () => $page->getByRole('button', ['name' => 'New chat', 'exact' => true])->click());
        $active = $page->locator('.chat-navigation-row.is-active');
        $active->waitFor();
        $active->getByRole('button', ['name' => 'Chat actions', 'exact' => true])->click();
        self::assertFalse($active->getByRole('button', ['name' => 'Save chat', 'exact' => true])->isVisible());

        $saved = FixtureStore::getFixtures()['demo_chat_5']->id;
        $savedRow = $page->locator('.chat-navigation-row[data-conversation-id="'.$saved.'"]');
        $this->visit($savedRow->locator('a.ea-sidebar-item-link')->getAttribute('href'));
        $savedRow->getByRole('button', ['name' => 'Chat actions', 'exact' => true])->click();
        $this->submit(static fn () => $savedRow->getByRole('button', ['name' => 'Save chat', 'exact' => true])->click());
        $page->locator('.chat-saved-group .chat-navigation-row.is-active')->waitFor();

        $other = FixtureStore::getFixtures()['demo_chat_1']->id;
        $this->visit($page->locator('.chat-navigation-row[data-conversation-id="'.$other.'"] a.ea-sidebar-item-link')->getAttribute('href'));
        $this->visit($savedRow->locator('a.ea-sidebar-item-link')->getAttribute('href'));
        $page->locator('.chat-saved-group .chat-navigation-row.is-active')->waitFor();
        $page->reload();
        self::assertSame($saved, $page->locator('.chat-navigation-row.is-active')->getAttribute('data-conversation-id'));
        self::assertTrue($page->locator('.chat-answer p')->filter(['hasText' => 'provisional'])->isVisible());

        $this->visit($page->locator('.chat-navigation-row[data-conversation-id="'.$other.'"] a.ea-sidebar-item-link')->getAttribute('href'));
        $page->locator('.chat-navigation-row[data-conversation-id="'.$other.'"].is-active')->waitFor();
        $page->getByRole('searchbox', ['name' => 'Search chats', 'exact' => true])->fill('provisional');
        $this->submit(static fn () => $page->getByRole('searchbox', ['name' => 'Search chats', 'exact' => true])->press('Enter'));
        $result = $page->locator('.chat-search-results li a[href*="conversation='.$saved.'"]');
        $result->waitFor();
        self::assertTrue($result->locator('span')->filter(['hasText' => 'provisional'])->isVisible());
        $this->visit($result->getAttribute('href'));
        $page->locator('.chat-navigation-row[data-conversation-id="'.$saved.'"].is-active')->waitFor();
        self::assertSame($saved, $page->locator('.chat-navigation-row.is-active')->getAttribute('data-conversation-id'));
        $savedRow->getByRole('button', ['name' => 'Chat actions', 'exact' => true])->click();
        $savedRow->getByRole('button', ['name' => 'Delete chat', 'exact' => true])->click();
        $page->locator('#modal-action-confirmation-button')->waitFor(['state' => 'visible']);
        $this->submit(static fn () => $page->locator('#modal-action-confirmation-button')->click());
        self::assertSame(0, $savedRow->count());
        self::assertSame(5, $page->locator('.chat-navigation-row')->count());
    }
}
